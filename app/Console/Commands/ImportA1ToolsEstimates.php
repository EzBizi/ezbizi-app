<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A1 Tools' estimates into EzBizi (docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md,
 * "Estimates importer"). The file's format and the rules are that section.
 *
 * The whole file is checked before anything is written, as the clients import
 * does: one defect anywhere refuses the run. Each estimate's customer comes
 * from the clients import's manifest; one the manifest does not place is
 * skipped and counted, never attached to an invented customer.
 *
 * History, so no automation event is raised, and A1's own created_at and total
 * are kept. Runs under the Workiz projection's lock, because the job link reads
 * what the projection writes. Nothing about a person is printed, only counts
 * and A1 estimate ids.
 */
class ImportA1ToolsEstimates extends Command
{
    public const FORMAT = 'ezbizi.a1-estimates/1';

    protected $signature = 'jobs:import-a1-estimates
        {file : The estimates JSON written by the A1 Tools export}
        {--clients-manifest= : The clients import manifest, a1-imports/clients-<time>.jsonl}
        {--dry-run : Check and count everything, writing nothing}
        {--wait=120 : Seconds to wait for a running Workiz projection}';

    protected $description = 'Import A1 Tools estimates onto the customers the clients import placed';

    private const TOP_KEYS = ['format', 'exported_at', 'organization', 'estimates'];

    private const KEYS = [
        'a1_id', 'a1_client_id', 'number', 'title', 'status', 'issued_on', 'accepted_at', 'created_at',
        'currency', 'total_minor', 'note', 'job_workiz_uuid', 'lines',
    ];

    private const LINE_KEYS = ['name', 'description', 'quantity', 'unit_price_minor'];

    /** A refusal prints this many problems and the total. */
    private const SHOWN = 50;

    /** @var string[] */
    private array $errors = [];

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers, AuditLogger $audit): int
    {
        $json = json_decode((string) @file_get_contents((string) $this->argument('file')), true);

        if (! is_array($json)) {
            return $this->refuse(['The file is not readable JSON.']);
        }

        foreach (array_diff(array_keys($json), self::TOP_KEYS) as $unknown) {
            $this->errors[] = "The file has a key this importer does not know: {$unknown}.";
        }

        if (($json['format'] ?? null) !== self::FORMAT) {
            $this->errors[] = 'The file is not format '.self::FORMAT.'.';
        }

        $manifest = $this->manifest((string) $this->option('clients-manifest'));

        // Not first() of a name: see ImportA1ToolsClients.
        $named = Organization::query()->where('name', $json['organization'] ?? '')->limit(2)->get();

        if ($named->count() !== 1) {
            return $this->refuse([...$this->errors, $named->isEmpty()
                ? 'The organization the file names does not exist.'
                : 'More than one organization has the name the file gives; nothing was chosen.']);
        }

        return $tenant->run($named->sole(), fn () => $this->import($json['estimates'] ?? null, $manifest, $numbers, $audit));
    }

    private function import(mixed $estimates, array $manifest, RecordNumberGenerator $numbers, AuditLogger $audit): int
    {
        $rows = $this->check($estimates);

        if ($this->errors !== []) {
            return $this->refuse($this->errors);
        }

        if ($this->option('dry-run')) {
            $this->report($this->match($rows, $manifest), dry: true);
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 3600);

        try {
            $lock->block((int) $this->option('wait'));
        } catch (LockTimeoutException) {
            $this->error('The Workiz projection is running. Nothing was written; run this again.');

            return self::FAILURE;
        }

        try {
            // Matched only now, under the lock: the projection could link a job
            // between a match and the write.
            $rows = $this->match($rows, $manifest);
            $batch = $this->write($rows, $numbers, $audit);
            $this->report($rows, dry: false);
            $this->info("Import batch {$batch}. Rollback: delete from estimates where source = 'a1' and import_batch = '{$batch}'.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    // ── read and check, write nothing ─────────────────────────────

    /** @return array<int, string> A1 client id => customer id, whatever the row's status there */
    private function manifest(string $path): array
    {
        $lines = $path === '' ? false : @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || $lines === []) {
            $this->errors[] = 'The clients manifest (--clients-manifest) is missing, unreadable or empty.';

            return [];
        }

        $map = [];

        foreach ($lines as $n => $line) {
            $row = json_decode($line, true);
            $a1 = $row['a1_id'] ?? null;
            $customer = $row['customer_id'] ?? null;

            if (! is_int($a1) || ! is_string($customer) || ! Str::isUuid($customer) || ($map[$a1] ?? $customer) !== $customer) {
                $this->errors[] = 'Clients manifest line '.($n + 1).' is not one A1 client id and one customer id.';

                continue;
            }

            $map[$a1] = $customer;
        }

        return $map;
    }

    private function check(mixed $estimates): array
    {
        if (! is_array($estimates) || ! array_is_list($estimates) || $estimates === []) {
            $this->errors[] = 'The file lists no estimates.';

            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($estimates as $i => $raw) {
            $a1 = is_array($raw) ? ($raw['a1_id'] ?? null) : null;
            $label = 'estimate '.($i + 1).(is_int($a1) ? " (A1 id {$a1})" : '');

            if (! is_array($raw) || array_is_list($raw)) {
                $this->errors[] = "{$label} is not an object.";

                continue;
            }

            $problems = $this->problems($raw);

            if ($problems === [] && isset($seen[$a1])) {
                $problems[] = "a1_id repeats estimate {$seen[$a1]}.";
            }

            foreach ($problems as $problem) {
                $this->errors[] = "{$label}: {$problem}";
            }

            if ($problems === []) {
                $seen[$a1] = $i + 1;
                $rows[] = $raw;
            }
        }

        return $rows;
    }

    /** @return string[] What is wrong with one estimate, against the doc's format. */
    private function problems(array $e): array
    {
        $p = [];

        foreach (array_diff(array_keys($e), self::KEYS) as $key) {
            $p[] = "unknown key {$key}.";
        }

        foreach (array_diff(self::KEYS, array_keys($e)) as $key) {
            $p[] = "missing key {$key}.";
        }

        if ($p !== []) {
            return $p;
        }

        $text = fn (mixed $v, int $max) => $v === null || (is_string($v) && $v !== '' && mb_strlen($v) <= $max);

        if (! is_int($e['a1_id']) || $e['a1_id'] < 1) {
            $p[] = 'a1_id is not a positive whole number.';
        }
        if (! is_int($e['a1_client_id'])) {
            $p[] = 'a1_client_id is not a whole number.';
        }
        if (! $text($e['number'], 32)) {
            $p[] = 'number is not text of at most 32 characters, or null.';
        }
        if (! $text($e['title'], 191)) {
            $p[] = 'title is not text of at most 191 characters, or null.';
        }
        if (! in_array($e['status'], Estimate::STATUSES, true)) {
            $p[] = 'status is not one of '.implode(', ', Estimate::STATUSES).'.';
        }
        if ($e['issued_on'] !== null && ! $this->isDate($e['issued_on'])) {
            $p[] = 'issued_on is not YYYY-MM-DD, or null.';
        }
        if ($e['accepted_at'] !== null && ! $this->isInstant($e['accepted_at'])) {
            $p[] = 'accepted_at is not an ISO 8601 instant, or null.';
        }
        if (! $this->isInstant($e['created_at'])) {
            $p[] = 'created_at is not an ISO 8601 instant.';
        }
        if (! is_string($e['currency']) || preg_match('/^[A-Z]{3}$/', $e['currency']) !== 1) {
            $p[] = 'currency is not an uppercase ISO 4217 code.';
        }
        if (! is_int($e['total_minor']) || $e['total_minor'] < 0) {
            $p[] = 'total_minor is not whole cents, 0 or more.';
        }
        if (! $text($e['note'], 60000)) {
            $p[] = 'note is not text, or null.';
        }
        if (! $text($e['job_workiz_uuid'], 64)) {
            $p[] = 'job_workiz_uuid is not text, or null.';
        }
        if (! is_array($e['lines']) || ! array_is_list($e['lines'])) {
            return [...$p, 'lines is not a list.'];
        }

        foreach ($e['lines'] as $n => $line) {
            $at = 'line '.($n + 1);

            if (! is_array($line) || array_diff(array_keys($line), self::LINE_KEYS) !== [] || array_diff(self::LINE_KEYS, array_keys($line)) !== []) {
                $p[] = "{$at} does not have exactly the keys ".implode(', ', self::LINE_KEYS).'.';

                continue;
            }
            if (! is_string($line['name']) || ! $text($line['name'], 191)) {
                $p[] = "{$at}: name is not text of 1 to 191 characters.";
            }
            if (! $text($line['description'], 60000)) {
                $p[] = "{$at}: description is not text, or null.";
            }
            // A string, never a float, so 1.15 cannot arrive as 1.149999.
            if (! is_string($line['quantity']) || preg_match('/^\d{1,8}(\.\d{1,2})?$/', $line['quantity']) !== 1) {
                $p[] = "{$at}: quantity is not a decimal string with at most 2 places.";
            }
            if (! is_int($line['unit_price_minor']) || $line['unit_price_minor'] < 0) {
                $p[] = "{$at}: unit_price_minor is not whole cents, 0 or more.";
            }
        }

        return $p;
    }

    /** Each row's outcome and where it goes. Reads only. */
    private function match(array $rows, array $manifest): array
    {
        $already = Estimate::query()->where('source', 'a1')
            ->whereIn('external_ref', array_map(fn (array $r) => (string) $r['a1_id'], $rows))
            ->pluck('external_ref')->flip();

        // Tenant-scoped: a manifest from another organization places nobody here.
        $customers = Customer::query()
            ->whereIn('id', array_values(array_unique(array_intersect_key($manifest, array_flip(array_column($rows, 'a1_client_id'))))))
            ->get(['id', 'owning_franchise_id'])->keyBy('id');
        $franchises = Franchise::query()->get()->keyBy('id');

        foreach ($rows as $i => $r) {
            $customer = $customers[$manifest[$r['a1_client_id']] ?? ''] ?? null;
            $franchise = $customer === null ? null : $franchises[$customer->owning_franchise_id] ?? null;

            $outcome = match (true) {
                $already->has((string) $r['a1_id']) => 'already',
                $franchise === null => 'no_customer',
                // The franchise's, as the API sets it, so an amount and its currency cannot disagree.
                $r['currency'] !== strtoupper($franchise->currency ?: 'USD') => 'currency',
                default => 'create',
            };

            $rows[$i] += [
                'outcome' => $outcome,
                'customer_id' => $customer?->getKey(),
                'franchise' => $franchise,
                // The EzBizi job mirroring that Workiz job, when it is this customer's here.
                'job_id' => $outcome === 'create' && $r['job_workiz_uuid'] !== null
                    ? Job::query()->where('customer_id', $customer->getKey())->where('franchise_id', $franchise->getKey())
                        ->whereHas('workizJob', fn ($q) => $q->where('remote_id', $r['job_workiz_uuid']))->value('id')
                    : null,
            ];
        }

        return $rows;
    }

    // ── write ─────────────────────────────────────────────────────

    /** @return string The run's import batch, the rollback's handle. */
    private function write(array $rows, RecordNumberGenerator $numbers, AuditLogger $audit): string
    {
        $batch = (string) Str::uuid();

        foreach ($rows as $r) {
            if ($r['outcome'] !== 'create') {
                continue;
            }

            // An estimate and its lines are one act. A run that dies keeps the
            // ones before it, and a re-run skips them by external_ref.
            DB::transaction(function () use ($r, $batch, $numbers) {
                $estimate = new Estimate([
                    'franchise_id' => $r['franchise']->getKey(),
                    'customer_id' => $r['customer_id'],
                    'job_id' => $r['job_id'],
                    'number' => $r['number'] ?? $numbers->next(RecordNumberGenerator::ESTIMATES, $r['franchise']),
                    'title' => $r['title'],
                    'status' => $r['status'],
                    'issued_on' => $r['issued_on'],
                    'note' => $r['note'],
                    'currency' => $r['currency'],
                    // A1's total, not the lines': 26 lines on 12 of 2,383 estimates.
                    'total_minor' => $r['total_minor'],
                    'source' => 'a1',
                    'external_ref' => (string) $r['a1_id'],
                    'import_batch' => $batch,
                ]);

                // A1's own dates: import time would file five years of history under today.
                $estimate->forceFill([
                    'accepted_at' => $r['accepted_at'] === null ? null : Carbon::parse($r['accepted_at'])->utc(),
                    'created_at' => Carbon::parse($r['created_at'])->utc(),
                ])->save();

                foreach ($r['lines'] as $position => $line) {
                    $estimate->lines()->create($line + ['position' => $position]);
                }
            });
        }

        $audit->log('estimate.imported', metadata: ['source' => 'a1tools', 'import_batch' => $batch] + $this->counts($rows));

        return $batch;
    }

    // ── report ────────────────────────────────────────────────────

    private function counts(array $rows): array
    {
        $outcomes = array_count_values(array_column($rows, 'outcome'));
        $created = array_filter($rows, fn (array $r) => $r['outcome'] === 'create' && $r['job_workiz_uuid'] !== null);

        return [
            'read' => count($rows),
            'created' => $outcomes['create'] ?? 0,
            'already_in' => $outcomes['already'] ?? 0,
            'skipped_no_customer' => $outcomes['no_customer'] ?? 0,
            'refused_currency' => $outcomes['currency'] ?? 0,
            'job_linked' => count(array_filter($created, fn (array $r) => $r['job_id'] !== null)),
            'job_unlinked' => count(array_filter($created, fn (array $r) => $r['job_id'] === null)),
        ];
    }

    private function report(array $rows, bool $dry): void
    {
        $c = $this->counts($rows);
        $skipped = array_column(array_filter($rows, fn (array $r) => $r['outcome'] === 'no_customer'), 'a1_id');

        $this->info("Estimates read: {$c['read']}");
        $this->line(($dry ? 'to create: ' : 'created: ').$c['created']);
        $this->line("already in: {$c['already_in']}");
        $this->line("skipped for no customer: {$c['skipped_no_customer']}"
            .($skipped === [] ? '' : ' (A1 estimate ids: '.implode(', ', $skipped).')'));
        $this->line("refused for a currency mismatch: {$c['refused_currency']}");
        $this->line("job linked: {$c['job_linked']}");
        $this->line("job unlinked: {$c['job_unlinked']}");
    }

    private function refuse(array $errors): int
    {
        $this->error('Refused. Nothing was written. '.count($errors).' problem(s):');

        foreach (array_slice($errors, 0, self::SHOWN) as $error) {
            $this->line("  - {$error}");
        }

        if (count($errors) > self::SHOWN) {
            $this->line('  … and '.(count($errors) - self::SHOWN).' more.');
        }

        return self::FAILURE;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function isInstant(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1
            && $this->isDate(substr($value, 0, 10));
    }
}
