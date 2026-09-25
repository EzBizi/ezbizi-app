<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One service area on one specific date: either the whole day closed (no
 * window) or one window's capacity that day. A date with any rows here uses
 * them instead of the weekly pattern — see the migration.
 */
#[Fillable(['organization_id', 'service_area_id', 'date', 'start_time', 'end_time', 'max_jobs'])]
class ServiceAreaDateOverride extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How much a service area could take on a given day. Configuration of the '
            .'business, not personal data, and it goes with the area.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'max_jobs' => 'integer',
        ];
    }
}
