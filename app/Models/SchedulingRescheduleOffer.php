<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An "earlier opening" text sent to a booked customer; the reply is on `lead_id`. */
#[Fillable(['organization_id', 'franchise_id', 'service_area_id', 'job_id', 'lead_id', 'open_date', 'booked_for', 'sent_by'])]
class SchedulingRescheduleOffer extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['open_date' => 'date:Y-m-d', 'booked_for' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'Which customers were offered an earlier day: kept a year, so nobody is offered twice and the office can see what was sent.');
    }
}
