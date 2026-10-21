<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One shape drawn over a service area; its ZIPs are the centroids inside. */
#[Fillable(['organization_id', 'franchise_id', 'service_area_id', 'points'])]
class ServiceAreaPolygon extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['points' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Where a service area works, drawn on a map. Goes with the area.');
    }
}
