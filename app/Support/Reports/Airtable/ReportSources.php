<?php

namespace App\Support\Reports\Airtable;

use App\Models\AirtableRecord;
use App\Models\ReportSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An organization's mapping of its Airtable tables into the reports, and the
 * rows a role holds for a company (report_sources over airtable_records).
 * Read outside a tenant, so the organization is always named.
 */
final class ReportSources
{
    /** role => what it is, for the Settings screen. */
    public const ROLES = [
        'pnl' => 'Profit & loss (one table per company)',
        'repair' => 'Repair teams',
        'deposits' => 'Deposits',
        'refunds' => 'Refunds',
        'disputes' => 'Disputes',
        'check_returns' => 'Returned checks',
        'hq_expenses' => 'HQ office expenses',
        'weekly_data' => 'Weekly data per company',
        'office_per_job' => 'Office cost per job',
        'monthly_bills' => 'Monthly bills',
        'lease_reviews' => 'Lease and reviews cost',
        'marketing' => 'Other marketing performance',
        'companies' => 'Companies',
    ];

    /** role => logical field => the field name used when a mapping names none. */
    public const FIELDS = [
        'refunds' => ['date' => 'Date', 'job' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Refund Amount', 'total' => 'Total Jobs', 'reason' => 'Reason', 'status' => 'Open/Close Job'],
        'disputes' => ['date' => 'Date', 'job' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Dispute Amount', 'total' => 'Total Jobs', 'reason' => 'Reason', 'status' => 'Open/Close Job'],
        'check_returns' => ['date' => 'Date', 'job' => 'Job Number', 'technician' => 'Technician Name', 'amount' => 'Amount of check', 'total' => 'Total Jobs', 'reason' => 'Type', 'status' => 'Open/Close Job'],
        'repair' => ['date' => 'Date', 'team' => 'Team', 'type' => 'Record type', 'summary' => 'Weekly Summary', 'job_amount' => 'Job Amount', 'parts' => 'Parts Cost',
            'tech_share' => 'Total Tech Share', 'external_share' => 'External Company Share', 'contracting' => 'Total Contracting Profit',
            'employee' => 'Employee Cost', 'helper' => 'Helper Cost', 'gas' => 'Gas Cost', 'operation' => 'Ave Operation Cost', 'hours' => 'Total Hours '],
        'hq_expenses' => ['date' => 'Date', 'name' => 'Category Name', 'amount' => 'Cost', 'skip' => 'Total'],
    ];

    /**
     * Each live row of $role for $company dated in the window, its fields
     * renamed to the logical names (FIELDS) so a section reads one shape.
     * $company null is the role's rows for every company.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rows(string $org, string $role, ?string $company, string $from, string $to): Collection
    {
        $out = collect();
        $sources = ReportSource::withoutGlobalScopes()->where('organization_id', $org)->where('role', $role)
            ->when($company !== null, fn ($q) => $q->where(fn ($q) => $q->where('company', $company)->orWhereNull('company')))
            ->get();

        foreach ($sources as $source) {
            $names = array_merge(self::FIELDS[$role] ?? [], (array) $source->fields);

            $records = AirtableRecord::withoutGlobalScopes()->where('organization_id', $org)
                ->where('base_id', $source->base_id)->where('table_id', $source->table_id)->whereNull('gone_at')->get();

            foreach ($records as $record) {
                $f = $record->fields;
                if ($source->filter_field !== null && strcasecmp(trim((string) ($f[$source->filter_field] ?? '')), (string) $source->filter_value) !== 0) {
                    continue;
                }
                $date = (string) ($f[$names['date'] ?? 'Date'] ?? '');
                if ($date < $from || $date > $to || $date === '') {
                    continue;
                }
                $row = ['company' => $source->company];
                foreach ($names as $logical => $field) {
                    $row[$logical] = $f[$field] ?? null;
                }
                $out->push($row);
            }
        }

        return $out;
    }

    /** The company code a branch reports as, or null when it has none set. */
    public static function companyOf(string $org, string $franchiseId): ?string
    {
        return DB::table('report_companies')->where('organization_id', $org)->where('franchise_id', $franchiseId)->value('code');
    }

    /** The logical value the mapping uses as a marker (repair 'summary', HQ 'skip'). */
    public static function marker(string $org, string $role, string $logical): string
    {
        $mapped = ReportSource::withoutGlobalScopes()->where('organization_id', $org)->where('role', $role)->first()?->fields;

        return (string) (($mapped[$logical] ?? null) ?? self::FIELDS[$role][$logical] ?? '');
    }
}
