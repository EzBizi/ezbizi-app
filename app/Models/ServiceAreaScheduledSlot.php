<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One window of a weekly pattern that takes over from `starts_on`. See the
 * migration `create_service_area_scheduled_slots`.
 */
#[Fillable([
    'organization_id', 'service_area_id', 'starts_on', 'weekday', 'start_time',
    'end_time', 'max_jobs',
])]
class ServiceAreaScheduledSlot extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A change to how much a service area takes, scheduled ahead. '
            .'Configuration of the business; it is folded into the weekly pattern '
            .'on the day it starts and removed.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'weekday' => 'integer',
            'max_jobs' => 'integer',
        ];
    }
}
