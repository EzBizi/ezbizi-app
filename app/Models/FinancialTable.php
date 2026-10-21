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
#[Fillable(['base_id', 'name', 'position', 'source_base_id', 'source_table_id', 'synced_at', 'cut_over_at', 'cut_over_by_user_id'])]
class FinancialTable extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'financial_tables';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A spreadsheet the organization keeps for itself. It stays until somebody deletes it '
            .'or the organization is removed.'
        );
    }

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'cut_over_at' => 'datetime'];
    }

    /** Kept in step with an Airtable table (and not yet cut over to EzBizi). */
    public function isMirror(): bool
    {
        return $this->source_table_id !== null && $this->cut_over_at === null;
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(FinancialBase::class, 'base_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FinancialField::class, 'table_id')->orderBy('position');
    }

    public function records(): HasMany
    {
        return $this->hasMany(FinancialRecord::class, 'table_id')->orderBy('position');
    }

    public function views(): HasMany
    {
        return $this->hasMany(FinancialView::class, 'table_id')->orderBy('position');
    }
}
