<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehicleEndedReason;
use App\Support\Fleet\VehicleOwnershipKind;
use App\Support\Fleet\VehiclePaymentMethod;
use App\Support\Fleet\VehicleRatePeriod;
use App\Support\Fleet\VehicleRentalTerms;
use Database\Factories\VehicleOwnershipPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owned/rented stretch for a vehicle. Open = `ended_on` null (at most one).
 */
#[Fillable([
    'vehicle_id', 'kind', 'counterparty', 'started_on', 'ended_on', 'ended_reason',
    'purchase_price_cents', 'lienholder', 'loan_account', 'loan_monthly_payment_cents',
    'loan_payoff_date', 'transport_cost_cents', 'rental_rate_cents', 'rental_rate_period',
    'rental_deposit_cents', 'rental_account_number', 'rental_payment_terms',
    'rental_payment_method',
])]
class VehicleOwnershipPeriod extends Model
{
    /** @use HasFactory<VehicleOwnershipPeriodFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use HasAttachments;

    protected function casts(): array
    {
        return [
            'kind' => VehicleOwnershipKind::class,
            'started_on' => 'date',
            'ended_on' => 'date',
            'ended_reason' => VehicleEndedReason::class,
            'purchase_price_cents' => 'integer',
            'loan_monthly_payment_cents' => 'integer',
            'loan_payoff_date' => 'date',
            'transport_cost_cents' => 'integer',
            'rental_rate_cents' => 'integer',
            'rental_rate_period' => VehicleRatePeriod::class,
            'rental_deposit_cents' => 'integer',
            'rental_payment_terms' => VehicleRentalTerms::class,
            'rental_payment_method' => VehiclePaymentMethod::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
