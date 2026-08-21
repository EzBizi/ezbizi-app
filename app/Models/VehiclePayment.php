<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehiclePaymentCategory;
use App\Support\Fleet\VehiclePaymentMethod;
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
class VehiclePayment extends Model
{
    /** @use HasFactory<VehiclePaymentFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
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

    public function ownershipPeriod(): BelongsTo
    {
        return $this->belongsTo(VehicleOwnershipPeriod::class, 'ownership_period_id');
    }

    public function insurancePolicy(): BelongsTo
    {
        return $this->belongsTo(VehicleInsurancePolicy::class, 'insurance_policy_id');
    }

    public function serviceVisit(): BelongsTo
    {
        return $this->belongsTo(VehicleServiceVisit::class, 'service_visit_id');
    }
}
