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

/**
 * A named region a franchise works in — "Dallas", "Houston"
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2).
 */
#[Fillable(['organization_id', 'franchise_id', 'name', 'available_from', 'shares_with_id', 'covers_all_zips'])]
class ServiceArea extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Where this franchise works. Part of how the business is set up rather '
            .'than a record of anything that happened, so it stays as long as the '
            .'franchise does and goes with it.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /** The area whose availability pool this one books into; null is its own. */
    public function sharesWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shares_with_id');
    }

    /**
     * The area that takes a ZIP: the one listing it, else the catch-all
     * (`covers_all_zips`) of a branch in scope. Limit to franchises when the
     * caller is one company's page or one person's view.
     *
     * @param  iterable<string>|null  $franchiseIds
     */
    public static function forZip(string $zip, ?iterable $franchiseIds = null): ?self
    {
        $digits = preg_replace('/\D+/', '', $zip);
        if (strlen($digits) < 4) {
            return null;
        }
        $zip = substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
        $franchiseIds = $franchiseIds === null ? null : collect($franchiseIds)->all();

        return ServiceAreaZip::query()->where('zip', $zip)
            ->when($franchiseIds !== null, fn ($q) => $q->whereIn('franchise_id', $franchiseIds))
            ->first()?->serviceArea
            ?? ($franchiseIds === null ? null : static::query()->whereIn('franchise_id', $franchiseIds)
                ->where('covers_all_zips', true)->orderBy('created_at')->first());
    }

    public function zips(): HasMany
    {
        return $this->hasMany(ServiceAreaZip::class);
    }

    protected function casts(): array
    {
        return ['covers_all_zips' => 'boolean', 'available_from' => 'date:Y-m-d', 'capacity_set_at' => 'datetime'];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ServiceAreaSlot::class);
    }
}
