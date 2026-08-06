<?php

namespace App\Support\Data;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data out (plan §12, §9).
 *
 * Streamed rather than assembled: an organization with fifty thousand customers
 * would otherwise build the whole file in memory before sending a byte, and the
 * first client big enough to matter is the one it fails for.
 */
class Exporter
{
    /** Rows per query. Big enough to be few round trips, small enough to stay flat. */
    private const CHUNK = 500;

    public function csv(string $resource): StreamedResponse
    {
        $definition = ResourceMap::get($resource);
        $columns    = $definition['columns'];

        return response()->streamDownload(function () use ($definition, $columns) {
            $out = fopen('php://output', 'w');

            // Excel reads a bare UTF-8 CSV as the system codepage, so an accented
            // name comes back mangled. The BOM is what makes it open correctly,
            // and every real customer list has accented names in it.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $columns);

            $definition['model']::query()
                ->orderBy('created_at')
                ->chunkById(self::CHUNK, function ($rows) use ($out, $columns) {
                    foreach ($rows as $row) {
                        fputcsv($out, array_map(
                            fn (string $c) => $this->cell($row->getAttribute($c)),
                            $columns,
                        ));
                    }

                    flush();
                });

            fclose($out);
        }, "{$resource}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Everything, as JSON — the §9 portability answer at organization level.
     *
     * Not streamed, because the point of this one is a single coherent document
     * somebody can hand to a lawyer or load into another system, and a truncated
     * JSON file is worthless in a way a truncated CSV is not.
     */
    public function bundle(): array
    {
        $out = [];

        foreach (ResourceMap::all() as $key => $definition) {
            $out[$key] = $definition['model']::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (Model $m) => $m->only($definition['columns']))
                ->all();
        }

        return $out;
    }

    /**
     * One person's data (§9, portability).
     *
     * Their record plus the work done for them. Deliberately not "every row that
     * mentions them": an audit entry naming who edited their address is somebody
     * else's activity record, and handing it over would be a disclosure rather
     * than a portability answer.
     */
    public function person(Customer $customer): array
    {
        $jobColumns = ResourceMap::get('jobs')['columns'];

        return [
            'customer' => $customer->only(ResourceMap::get('customers')['columns']),
            'jobs'     => $customer->jobs()->orderBy('created_at')->get()
                ->map(fn (Model $j) => $j->only($jobColumns))->all(),
        ];
    }

    /**
     * Defuse spreadsheet formulas.
     *
     * A cell beginning = + - @ or a control character is executed when the file
     * is opened, so a customer named `=HYPERLINK("http://evil","click")` turns
     * an export into an attack on whoever opens it. Excel and Sheets both do
     * this, and the data got into the system through a legitimate form.
     *
     * Numbers are left alone — prefixing `-5` would corrupt the value, and a
     * number cannot carry a formula.
     */
    private function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return $value === false ? '0' : '';
        }

        if ($value === true) {
            return '1';
        }

        $string = (string) $value;

        if ($string === '' || is_numeric($string)) {
            return $string;
        }

        return str_contains("=+-@\t\r", $string[0]) ? "'".$string : $string;
    }
}
