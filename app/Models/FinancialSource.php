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
        $table = $this->mirrorTable();
        $ezbizi = match (self::$readFrom) {
            'airtable' => false,
            'ezbizi' => true,
            default => $table?->isCutOver() ?? false,
        };

        $rows = $ezbizi
            ? ($table === null ? collect() : collect(self::ezbiziRows($table))->pluck('fields'))
            : collect(self::airtableRows($this->base_id, $this->table_id))->pluck('fields');

        return $rows->filter(fn (array $fields) => $this->owns($fields))->values();
    }

    /** Which store rows() is forced to read; null = the live one. Set only by readingFrom(). */
    private static ?string $readFrom = null;

    /**
     * Runs $fn with every rows() reading one store ('airtable' or 'ezbizi'),
     * as parity compares the two; the previous setting comes back even if
     * $fn throws.
     */
    public static function readingFrom(string $store, callable $fn): mixed
    {
        if (! in_array($store, ['airtable', 'ezbizi'], true)) {
            throw new \InvalidArgumentException("Unknown store {$store}.");
        }
        $before = self::$readFrom;
        self::$readFrom = $store;
        try {
            return $fn();
        } finally {
            self::$readFrom = $before;
        }
    }

    /** The store rows() is forced to read right now, if any. */
    public static function forcedStore(): ?string
    {
        return self::$readFrom;
    }

    /** The Financials table that mirrors (or took over) this source's Airtable table. */
    public function mirrorTable(): ?FinancialTable
    {
        return FinancialTable::query()->where('source_base_id', $this->base_id)
            ->where('source_table_id', $this->table_id)->orderBy('created_at')->first();
    }

    /** @return list<array{id: string, fields: array<string, mixed>}> the Airtable copy, live rows */
    public static function airtableRows(string $baseId, string $tableId): array
    {
        return AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)
            ->whereNull('gone_at')->get(['record_id', 'fields'])
            ->map(fn (AirtableRecord $r) => ['id' => $r->record_id, 'fields' => (array) $r->fields])->all();
    }

    /**
     * A Financials table's rows with values mapped back to the Airtable column
     * names, so field maps keep working. id = the Airtable record it came
     * from; null = entered in EzBizi.
     *
     * @return list<array{id: ?string, fields: array<string, mixed>}>
     */
    public static function ezbiziRows(FinancialTable $table): array
    {
        $names = $table->fields()->get()->mapWithKeys(fn (FinancialField $f) => [$f->id => $f->source_name ?? $f->name]);

        return $table->records()->get()->map(function (FinancialRecord $r) use ($names) {
            $out = [];
            foreach ((array) $r->values as $fieldId => $value) {
                if (isset($names[$fieldId])) {
                    $out[$names[$fieldId]] = $value;
                }
            }

            return ['id' => $r->source_record_id, 'fields' => $out];
        })->all();
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
