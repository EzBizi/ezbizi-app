<?php

namespace App\Support\Reports\Airtable;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use Illuminate\Support\Collection;

/**
 * The rows an organization's mapped Airtable tables hold for one part of the
 * report and one branch: Financials' mapping (financial_sources, set in
 * Financials → Settings) over the hourly copy (airtable_records). Read
 * outside a tenant, so the organization is always named.
 */
final class ReportSources
{
    /**
     * Column names used when a source's field map names none. They are only a
     * starting point for a new mapping; each tenant's own map wins.
     *
     * role => logical key => column
     */
    public const FIELDS = [
        'refunds' => ['date' => 'Date', 'job_number' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Refund Amount', 'total' => 'Total Jobs', 'reason' => 'Reason', 'status' => 'Open/Close Job'],
        'disputes' => ['date' => 'Date', 'job_number' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Dispute Amount', 'total' => 'Total Jobs', 'reason' => 'Reason', 'status' => 'Open/Close Job'],
        'check_returns' => ['date' => 'Date', 'job_number' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Amount of check', 'total' => 'Total Jobs', 'reason' => 'Type', 'status' => 'Open/Close Job'],
        'repair' => ['date' => 'Date', 'team' => 'Team', 'kind' => 'Record type', 'amount' => 'Job Amount', 'parts' => 'Parts Cost',
            'tech_share' => 'Total Tech Share', 'external_share' => 'External Company Share', 'contracting' => 'Total Contracting Profit',
            'employee' => 'Employee Cost', 'helper' => 'Helper Cost', 'gas' => 'Gas Cost', 'operation' => 'Ave Operation Cost', 'hours' => 'Total Hours '],
        'hq_expenses' => ['date' => 'Date', 'category' => 'Category Name', 'amount' => 'Cost'],
        'pnl' => [],
        // Other marketing (an agency's or a directory's own sheet), per area and day.
        'marketing' => ['date' => 'DATE', 'area' => 'Service Area', 'amount' => 'COST', 'calls' => 'CALLS', 'jobs' => 'JOBS', 'sales' => 'TOTAL SALES', 'profit' => 'PROFIT'],
    ];

    /**
     * Each live row of $role dated in the window, renamed to the logical keys.
     * With a branch: only sources mapped to it (a per-place source with no
     * branch yet is not mapped, and reads as nothing). Without: the
     * organization's own (HQ) sources.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rows(string $org, string $role, ?string $franchiseId, string $from, string $to): Collection
    {
        $out = collect();

        foreach (self::sources($org, $role, $franchiseId) as $source) {
            $names = array_merge(self::FIELDS[$role] ?? [], (array) $source->field_map);

            foreach (self::records($org, $source) as $f) {
                if (! $source->owns($f)) {
                    continue;
                }
                $date = (string) ($f[$names['date'] ?? 'Date'] ?? '');
                // A table that stands for the branch only for some dates (2026-10-16:
                // Washington's P&L was New York's until 9-20) gives only its own days.
                if ($date === '' || $date < $from || $date > $to || ! $source->covers($date)) {
                    continue;
                }
                $row = [];
                foreach ($names as $logical => $field) {
                    $row[$logical] = $f[$field] ?? null;
                }
                $out->push($row);
            }
        }

        return $out;
    }

    /**
     * Every live row's fields of $role for the branch, for sources valid in
     * the dates; a dated row only inside its source's validity (the P&L reads
     * its weeks itself).
     *
     * @return list<array<string, mixed>>
     */
    public static function allRows(string $org, string $role, ?string $franchiseId, string $from = '0000-01-01', string $to = '9999-12-31'): array
    {
        $out = [];
        foreach (self::sources($org, $role, $franchiseId) as $source) {
            if (! $source->overlaps($from, $to)) {
                continue;
            }
            $dateField = (array) $source->field_map + ['date' => 'Date'];
            foreach (self::records($org, $source) as $f) {
                $date = (string) ($f[$dateField['date']] ?? '');
                if ($source->owns($f) && ($date === '' || $source->covers($date))) {
                    $out[] = $f;
                }
            }
        }

        return $out;
    }

    /** A value marker of the role's first source (repair 'summary', HQ 'skip'), or $default. */
    public static function marker(string $org, string $role, ?string $franchiseId, string $key, string $default = ''): string
    {
        return self::sources($org, $role, $franchiseId)->first()?->marker($key) ?? $default;
    }

    /** Is any source of $role mapped for this branch (or for HQ, with null)? */
    public static function mapped(string $org, string $role, ?string $franchiseId): bool
    {
        return self::sources($org, $role, $franchiseId)->isNotEmpty();
    }

    /** @return Collection<int, FinancialSource> */
    private static function sources(string $org, string $role, ?string $franchiseId): Collection
    {
        return FinancialSource::withoutGlobalScopes()->where('organization_id', $org)->where('role', $role)
            ->when($franchiseId !== null, fn ($q) => $q->where('franchise_id', $franchiseId), fn ($q) => $q->whereNull('franchise_id')->whereNull('company_id'))
            ->get();
    }

    /** @return list<array<string, mixed>> */
    private static function records(string $org, FinancialSource $source): array
    {
        return AirtableRecord::withoutGlobalScopes()->where('organization_id', $org)
            ->where('base_id', $source->base_id)->where('table_id', $source->table_id)->whereNull('gone_at')
            ->pluck('fields')->map(fn ($f) => is_array($f) ? $f : (array) json_decode((string) $f, true))->all();
    }
}
