<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A franchise's helper, booked to technicians on Scheduling › Helpers. */
#[Fillable(['organization_id', 'franchise_id', 'name', 'notes', 'is_active'])]
class SchedulingHelper extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Part of how a franchise runs its crews: retired rather than deleted, so past '
            .'bookings keep a name, and gone with the franchise.'
        );
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(SchedulingHelperBooking::class, 'helper_id');
    }
}
