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
 * An appointment asked for on a website's booking widget, waiting for a
 * dispatcher in Leads ▸ Online Booking (Jose, 2026-10-08). Since 2026-11-03
 * also one the booking bot offered or held in a chat: no site, and the Workiz
 * job type the chat settled on (BookingBot).
 */
#[Fillable(['site_id', 'lead_id', 'service_area_id', 'date', 'start_time', 'end_time', 'status', 'job_type'])]
class WebBooking extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    /** Offered in a chat and not yet agreed to: holds no seat (BookingBot). */
    public const OFFERED = 'offered';

    /** The statuses that hold a seat (Openings), until the lead has a job. */
    public const HOLDING = [self::PENDING, self::APPROVED];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The day and arrival window somebody asked for on a website\'s booking form, and '
            .'whether it was approved. Who they are is on the enquiry it belongs to; deleted with it.'
        );
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
