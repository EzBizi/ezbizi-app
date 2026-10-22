<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Airtable table's part in an organization's books, and which of its
 * columns mean what. Null company and place = the organization itself (HQ).
 */
#[Fillable(['base_id', 'table_id', 'role', 'company_id', 'franchise_id', 'filter_field', 'filter_value', 'field_map', 'markers', 'valid_from', 'valid_until'])]
class FinancialSource extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** role => what a person calls it */
    public const ROLES = [
        'pnl' => 'Profit & loss',
        'repair' => 'Repair team sheet',
        'deposits' => 'Deposits',
        'refunds' => 'Refunds',
        'disputes' => 'Disputes',
        'check_returns' => 'Returned checks',
        'hq_expenses' => 'Head-office expenses',
        'weekly_data' => 'Expenses by company',
        'monthly_bills' => 'Monthly bills',
        'lease_reviews' => 'Lease and review costs',
        'marketing' => 'Marketing performance',
        'office_per_job' => 'Office cost per job',
        'companies' => 'Company list',
        'payroll' => 'Technician payroll',
    ];

    /** Column meanings a field map may name. */
    /** Marker meanings: values that tell rows apart. */
    public const MARKER_KEYS = ['summary', 'job', 'skip'];

    public const FIELD_KEYS = [
        'amount', 'date', 'company', 'technician', 'team', 'job_number', 'category', 'kind', 'status', 'reason', 'total', 'jobs',
        // Repair team sheets.
        'parts', 'tech_share', 'external_share', 'contracting', 'employee', 'helper', 'gas', 'operation', 'hours',
        'job_amount', 'approval',
    ];

    protected function casts(): array
    {
        return ['field_map' => 'array', 'markers' => 'array', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Configuration: which of the business\'s Airtable tables feeds which report. '
            .'It stays until somebody removes it or the organization is removed.'
        );
    }

    /** @return Collection<int, self> */
    public static function forRole(string $role): Collection
    {
        return self::query()->where('role', $role)->get();
    }

    public function field(string $key): ?string
    {
        return $this->field_map[$key] ?? null;
    }

    public function marker(string $key): ?string
    {
        return $this->markers[$key] ?? null;
    }

    /** Whether this source holds the books for any day of [$from, $to] (Y-m-d). */
    public function overlaps(string $from, string $to): bool
    {
        return ($this->valid_from === null || $this->valid_from->toDateString() <= $to)
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $from);
    }

    /** Whether one day (Y-m-d) falls inside this source's validity. */
    public function covers(string $date): bool
    {
        return $this->overlaps($date, $date);
    }

    /**
     * This source's rows, as Airtable column name => value, from whichever
     * store is live: the Airtable copy, or — once its table was cut over to
     * EzBizi — the Financials table (values mapped back to the Airtable column
     * names, so field maps keep working). Only rows that are this place's
     * (owns()) and not gone. Dates and validity are the caller's.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): \Illuminate\Support\Collection
    {
        $cut = FinancialTable::query()->where('source_base_id', $this->base_id)->where('source_table_id', $this->table_id)
            ->whereNotNull('cut_over_at')->first();

        if ($cut !== null) {
            $names = $cut->fields()->get()->mapWithKeys(fn (FinancialField $f) => [$f->id => $f->source_name ?? $f->name]);
            $rows = $cut->records()->get()->map(function (FinancialRecord $r) use ($names) {
                $out = [];
                foreach ((array) $r->values as $fieldId => $value) {
                    if (isset($names[$fieldId])) {
                        $out[$names[$fieldId]] = $value;
                    }
                }

                return $out;
            });
        } else {
            $rows = AirtableRecord::query()->where('base_id', $this->base_id)->where('table_id', $this->table_id)
                ->whereNull('gone_at')->get(['fields'])->map(fn (AirtableRecord $r) => (array) $r->fields);
        }

        return $rows->filter(fn (array $fields) => $this->owns($fields))->values();
    }

    /** Whether a copied Airtable row belongs to this source's place. */
    public function owns(array $fields): bool
    {
        return $this->filter_field === null
            || trim((string) ($fields[$this->filter_field] ?? '')) === $this->filter_value;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
