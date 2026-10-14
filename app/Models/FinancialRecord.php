<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Financials (the Airtable replacement) — see the create_financials_tables migration. */
#[Fillable(['table_id', 'values', 'position', 'created_by_user_id'])]
class FinancialRecord extends Model implements CarriesAutomationScope, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'financial_records';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A spreadsheet the organization keeps for itself. It stays until somebody deletes it '
            .'or the organization is removed.'
        );
    }

    /** Where the record's base is filed: its franchise, else its company, else the whole organization. */
    public function automationScope(): array
    {
        // $this->table is the table NAME (Eloquent's property), not the relation.
        $base = FinancialTable::find($this->table_id)?->base;

        return match (true) {
            $base?->franchise_id !== null => ['franchise', $base->franchise_id],
            $base?->company_id !== null => ['company', $base->company_id],
            default => [null, null],
        };
    }

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(FinancialTable::class, 'table_id');
    }
}
