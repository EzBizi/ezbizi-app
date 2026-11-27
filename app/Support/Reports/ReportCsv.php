<?php

namespace App\Support\Reports;

use App\Support\Data\Exporter;

/**
 * A report run as CSV.
 *
 * Its own class because two things want it — a mailed report and, when it is
 * built, the export control — and a second copy is how the two would drift into
 * disagreeing about quoting.
 */
final class ReportCsv
{
    public static function render(ReportResult $result): string
    {
        $out = fopen('php://temp', 'r+');

        fputcsv($out, array_column($result->columns, 'label'));

        foreach ($result->rows as $row) {
            fputcsv($out, array_map(
                fn (array $column) => self::cell($row[$column['key']] ?? null),
                $result->columns,
            ));
        }

        /*
         * The cap is stated IN the file rather than only in the covering email.
         * A CSV outlives the message it arrived in — it gets saved, forwarded
         * and opened next month — and a truncated one that does not say so is
         * indistinguishable from a complete one. Somebody totals the column and
         * is quietly wrong.
         */
        if ($result->capped) {
            fputcsv($out, []);
            fputcsv($out, ['Showing the first '.$result->count.' rows. This report matched more.']);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        // Every export's formula rule (audit 2026-11-27: a name typed by an outsider ran as a formula).
        return Exporter::cell((string) $value);
    }
}
