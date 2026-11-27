<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
        return $this->source_table_id !== null && ! $this->isCutOver();
    }

    /**
     * EzBizi holds this table: its own cut_over_at, or its whole Airtable base
     * switched by config('airtable.cutover_bases').
     */
    public function isCutOver(): bool
    {
        return $this->cut_over_at !== null
            || ($this->source_base_id !== null && in_array($this->source_base_id, self::cutOverBases(), true));
    }

    /** @return list<string> */
    public static function cutOverBases(): array
    {
        return (array) config('airtable.cutover_bases', []);
    }

    public function scopeCutOver(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('cut_over_at')->orWhereIn('source_base_id', self::cutOverBases()));
    }

    /** Linked to Airtable and still following it. */
    public function scopeStillMirrored(Builder $query): void
    {
        $query->whereNotNull('source_table_id')->whereNull('cut_over_at')
            ->when(self::cutOverBases() !== [], fn (Builder $q) => $q->whereNotIn('source_base_id', self::cutOverBases()));
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
