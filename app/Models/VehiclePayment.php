<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehiclePaymentCategory;
use App\Support\Fleet\VehiclePaymentMethod;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\VehiclePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One money trail row on a vehicle (purchase, loan, insurance, rental, …). */
#[Fillable([
    'vehicle_id', 'category', 'amount_cents', 'occurred_on', 'method', 'note',
    'ownership_period_id', 'insurance_policy_id', 'service_visit_id',
])]
class VehiclePayment extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Money paid against the vehicle. It hangs off the van and is removed '
            .'with it. A payment is a fact about that vehicle, not a log that ages out '
            .'on its own.'
        );
    }

    /** @use HasFactory<VehiclePaymentFactory> */
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
            'category' => VehiclePaymentCategory::class,
            'amount_cents' => 'integer',
            'occurred_on' => 'date',
            'method' => VehiclePaymentMethod::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
