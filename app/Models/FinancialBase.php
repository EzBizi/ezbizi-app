<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Financials (the Airtable replacement) — see the create_financials_tables migration. */
#[Fillable(['company_id', 'franchise_id', 'name', 'colour', 'created_by_user_id'])]
class FinancialBase extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'financial_bases';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A spreadsheet the organization keeps for itself. It stays until somebody deletes it '
            .'or the organization is removed.'
        );
    }

    public function tables(): HasMany
    {
        return $this->hasMany(FinancialTable::class, 'base_id')->orderBy('position');
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
