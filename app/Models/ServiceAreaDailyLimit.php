<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The most jobs one service area takes on a weekday, whatever its time slots
 * add up to. No row is no limit. See the migration
 * `create_service_area_daily_limits`, and Openings for how it caps a day.
 */
#[Fillable(['organization_id', 'service_area_id', 'weekday', 'max_jobs'])]
class ServiceAreaDailyLimit extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How many jobs a service area takes in a day. Configuration of the '
            .'business, not a record of a day, so it does not accumulate.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'max_jobs' => 'integer',
        ];
    }
}
