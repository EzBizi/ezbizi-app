<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehiclePremiumPeriod;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\VehicleInsurancePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One insurance policy period on a vehicle. Open = `ended_on` null (at most one).
 */
#[Fillable([
    'vehicle_id', 'carrier', 'policy_number', 'coverage', 'started_on',
    'expires_on', 'ended_on', 'premium_cents', 'premium_period',
    'agent', 'agent_phone', 'listed_drivers',
])]
class VehicleInsurancePolicy extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A policy period is evidence that a van was covered on a given day. It '
            .'goes when the vehicle goes. Expiry is a date on the row, not a reason '
            .'to delete the history.'
        );
    }

    /** @use HasFactory<VehicleInsurancePolicyFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its vehicle's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['vehicles', Vehicle::class, 'vehicle_id'];

    use HasAttachments;

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'expires_on' => 'date',
            'ended_on' => 'date',
            'premium_cents' => 'integer',
            'premium_period' => VehiclePremiumPeriod::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
