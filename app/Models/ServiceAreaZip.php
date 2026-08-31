<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One zip code inside one service area
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2).
 *
 * A zip is not personal data here. It says where a franchise works, not where
 * anybody lives — the customer's own zip lives on Customer.
 */
#[Fillable(['organization_id', 'franchise_id', 'service_area_id', 'zip'])]
class ServiceAreaZip extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which zip codes a service area covers. Goes with the area, which goes '
            .'with the franchise.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
