<?php

namespace App\Support\Data;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Data in (plan §12, §11.3 "Import validation & partial failure").
 *
 * The plan calls import "the first thing a new client touches", which decides
 * the shape of this: it tells you everything that is wrong with the file, by row
 * number, rather than stopping at the first bad line. A trial user with a
 * thousand-row export from their old system will not fix it one error per upload
 * — they will give up.
 *
 * Partial failure is deliberate. Each row commits on its own, so 998 good rows
 * load and 2 bad ones come back to be corrected. Rolling the lot back because of
 * a missing postcode is the behaviour that makes people paste rows in by hand.
 */
class Importer
{
    /**
     * A ceiling, not a business rule: a file this size is a paste accident or an
     * attempt to tie up a request thread. Real migrations that exceed it are a
     * conversation, not a form submission.
     */
    private const MAX_ROWS = 5000;

    /** Enough to fix the file in one pass; not so many the response is unusable. */
    private const MAX_REPORTED_ERRORS = 100;

    /** Marker for a row with more cells than columns — see parse(). */
    private const TOO_WIDE = '__too_wide__';

    public function __construct(
        private RecordNumberGenerator $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{imported:int, failed:int, errors:array, dry_run:bool, truncated:bool}
     */
    public function run(string $resource, string $csv, Franchise $franchise, bool $dryRun = false): array
    {
        $definition = ResourceMap::get($resource);

        if ($definition === null || ! isset($definition['import'])) {
            throw new RuntimeException("'{$resource}' cannot be imported.");
        }

        $spec = $definition['import'];

        // The export's own columns are known too, so the app's export imports
        // back as it is. The export-only ones (`created_at`, `owning_company`)
        // are read and then dropped: validated() keeps only the import columns.
        $rows = $this->parse($csv, array_values(array_unique([
            ...array_keys($spec['columns']), ...$definition['columns'],
        ])));

        $imported = 0;
        $errors = [];

        foreach ($rows as $number => $row) {
            $failure = $row === self::TOO_WIDE
                ? ['This row has more values than there are columns — most likely a comma '
                    .'inside a value that is not wrapped in quotes. Everything after it '
                    .'would land in the wrong field.']
                : $this->one($definition, $spec, $row, $franchise, $dryRun);

            if ($failure === null) {
                $imported++;

                continue;
            }

            // Still counted after the report is capped, so the summary stays
            // truthful even when the list is trimmed.
            if (count($errors) < self::MAX_REPORTED_ERRORS) {
                $errors[] = ['row' => $number, 'messages' => $failure];
            } else {
                $errors[] = null;
            }
        }

        $failed = count($errors);
        $errors = array_values(array_filter($errors));

        if (! $dryRun && $imported > 0) {
            // ONE audit entry for the whole file, and deliberately NO automation
            // events at all.
            //
            // Per-row events would mean a three-thousand-customer migration
            // firing three thousand "new customer" rules — welcome emails to an
            // entire existing customer base, on their first day. §5.5's loop
            // guards count actions from one originating event and would not
            // catch it, because every row is its own origin.
            $this->audit->log('data.imported', metadata: [
                'resource' => $resource, 'imported' => $imported, 'failed' => $failed,
            ]);
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
            'dry_run' => $dryRun,
            'truncated' => $failed > count($errors),
        ];
    }

    /** @return string[]|null  messages, or null if the row landed. */
    private function one(array $definition, array $spec, array $row, Franchise $franchise, bool $dryRun): ?array
    {
        foreach ($spec['booleans'] ?? [] as $column) {
            if (($row[$column] ?? '') !== '') {
                // An unparseable value ("maybe") stays as typed so the `boolean`
                // rule refuses it. Turning it into null read it as "not given"
                // and the row landed with the column silently ignored.
                $row[$column] = filter_var($row[$column], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? $row[$column];
            }
        }

        // Blank cells mean "not given", not "set this to empty string" — a CSV
        // has no way to say null, and every export produces blanks.
        $row = array_map(fn ($v) => $v === '' ? null : $v, $row);

        $validator = Validator::make($row, $spec['columns']);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $input = $validator->validated();
        $model = $definition['model'];

        // Re-importing an export must not double every record. Refused rather
        // than overwritten: an import that silently updates existing rows is how
        // somebody's corrected address gets replaced by the stale one they were
        // trying to fix.
        if (isset($spec['unique'], $input[$spec['unique']])) {
            $exists = $model::query()
                ->where($spec['unique'], $input[$spec['unique']])
                ->exists();

            if ($exists) {
                return [sprintf(
                    'A record with %s "%s" already exists. Remove the column to create new records, '
                    .'or clear that cell for this row.',
                    $spec['unique'], $input[$spec['unique']],
                )];
            }
        }

        if ($dryRun) {
            return null;
        }

        try {
            DB::transaction(function () use ($model, $input, $franchise, $definition) {
                // `isset`, not a `+` union with a fallback.
                //
                // A blank `number` cell becomes a present-but-null key, and PHP's
                // `+` gives the LEFT operand precedence on duplicate keys — so
                // the null won, the generator was never consulted, and the row
                // went in with no record number at all. Every export includes a
                // `number` column, so re-importing an edited export with one
                // blank cell hit this immediately.
                $sequence = $this->sequenceFor($definition);

                if (! isset($input['number'])) {
                    $input['number'] = $this->numbers->next($sequence, $franchise);
                } else {
                    // An imported C-00042 is a number the generator would hand
                    // out itself one day, and then every create in the
                    // organization would collide with it and roll back. Move
                    // the sequence past it in the same transaction.
                    $this->numbers->advancePast($sequence, $franchise, (string) $input['number']);
                }

                $model::create($input + [
                    $definition['import']['franchise_column'] => $franchise->id,
                ]);
            });
        } catch (\Throwable $e) {
            // A row failing for a reason validation could not see — a race on the
            // unique index, a constraint — must not take the rest of the file
            // with it. The message is the point of the whole feature.
            return [$e instanceof QueryException
                ? $this->databaseRefusal($e)
                : $e->getMessage()];
        }

        return null;
    }

    /**
     * Say what the database actually refused, by its error number.
     *
     * Every failure used to read "conflicts with an existing record", which
     * sent people hunting for a duplicate that was never there when the real
     * problem was a value too long for its column.
     */
    private function databaseRefusal(QueryException $e): string
    {
        return match ((int) ($e->errorInfo[1] ?? 0)) {
            1062 => 'Could not be saved — it conflicts with an existing record.',
            1406 => 'Could not be saved — a value is too long for its column.',
            1366, 1292, 1264 => 'Could not be saved — a value is not in a form that column accepts.',
            1451, 1452 => 'Could not be saved — it refers to a record that does not exist.',
            1048 => 'Could not be saved — a required value is missing.',
            default => 'Could not be saved because of a database error.',
        };
    }

    /**
     * Undo the export's spreadsheet-formula escape.
     *
     * Exporter::cell prefixes a leading = + - @ with an apostrophe so Excel does
     * not execute the cell. Without stripping it here, exporting and re-importing
     * a phone number turns `+1 415 555 0100` into `'+1 415 555 0100` and does it
     * again on every round trip. Only a marker in front of a character that
     * actually needed escaping is removed, so a name that genuinely starts with
     * an apostrophe survives.
     */
    private function uncell(mixed $value): string
    {
        $string = trim((string) $value);

        return (strlen($string) > 1 && $string[0] === "'" && str_contains('=+-@', $string[1]))
            ? substr($string, 1)
            : $string;
    }

    private function sequenceFor(array $definition): string
    {
        return match ($definition['model']) {
            Customer::class => RecordNumberGenerator::CUSTOMERS,
            Job::class => RecordNumberGenerator::JOBS,
        };
    }

    /**
     * @param  string[]  $known
     * @return array<int, array<string, string>> row number (as the user sees it) => cells
     */
    private function parse(string $csv, array $known): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);
            throw new RuntimeException('That file has no rows in it.');
        }

        // Excel writes a BOM on every CSV it saves, which otherwise becomes part
        // of the first column name and makes it "unknown" — on the file the user
        // just exported from us.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $unknown = array_diff($header, $known);

        if ($unknown !== []) {
            fclose($handle);
            throw new RuntimeException(sprintf(
                'Unrecognised column%s: %s. Expected any of: %s.',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }

        $rows = [];
        $number = 1;   // the header is row 1, so data starts at 2

        while (($cells = fgetcsv($handle)) !== false && $cells !== null) {
            $number++;

            // A trailing newline produces one of these, and refusing the file
            // over it would be maddening.
            if ($cells === [null] || $cells === ['']) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new RuntimeException(sprintf(
                    'That file has more than %s rows. Split it, or ask us to run the import for you.',
                    number_format(self::MAX_ROWS),
                ));
            }

            // Short rows are common in hand-edited files — pad rather than
            // refuse. A row with MORE cells than columns is a different thing
            // entirely: an unquoted comma inside a value, which shifts every
            // later column by one. Truncating it silently discarded the tail and
            // wrote the fragment after the comma into the next field, and the
            // result validated clean. Reported as a row failure instead.
            if (count($cells) > count($header)) {
                $rows[$number] = self::TOO_WIDE;

                continue;
            }

            $cells = array_pad($cells, count($header), '');

            $rows[$number] = array_map(fn ($v) => $this->uncell($v), array_combine($header, $cells));
        }

        fclose($handle);

        return $rows;
    }
}
