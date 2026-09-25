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
#[Fillable(['organization_id', 'franchise_id', 'name', 'available_from'])]
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

    public function zips(): HasMany
    {
        return $this->hasMany(ServiceAreaZip::class);
    }

    /** Specific dates that replace the weekly pattern. */
    public function dateOverrides(): HasMany
    {
        return $this->hasMany(ServiceAreaDateOverride::class);
    }

    protected function casts(): array
    {
        return ['available_from' => 'date:Y-m-d'];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ServiceAreaSlot::class);
    }
}
