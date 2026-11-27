<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleName;
use App\Support\Fleet\VehicleStatus;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One vehicle on the fleet register (spec §5).
 *
 * Display `label` is derived from identity fields, never typed. Soft-delete is
 * not used: sold/returned stay as statuses; hard delete only when isEmpty().
 */
#[Fillable([
    'company_id', 'franchise_id', 'number', 'year', 'make', 'model', 'trim',
    'plate', 'plate_state', 'vin', 'colour', 'body_type', 'fuel_type',
    'odometer_miles', 'odometer_updated_at', 'next_service_on',
    'next_service_miles', 'registration_expires_on', 'inspection_expires_on',
    'toll_device_number', 'toll_plan', 'toll_flat_rate_cents', 'status',
    'notes',
])]
class Vehicle extends Model implements StatesRetention
{
    /**
     * The fields every vehicle is expected to have: counted when empty beside
     * the row in the Fleet list, and drawn amber ("Not filled in") on the
     * Identity card. The same look and the same rule as HR's record
     * (User::PROFILE_FIELDS) and Web Management's SEO, asked for by
     * Analorena on 2026-10-01 and built on Jose's "implement them all".
     *
     * A deliberate subset, for HR's reason: a field many vehicles truly lack
     * would stay amber for ever, because nothing can record "has none", and
     * the count could never reach zero. So not trim, notes or tolls (plenty
     * of vans have none), not inspection expiry (many states never inspect),
     * and not the toll flat rate, which is money. Next service is counted
     * separately in VehiclesController::missing — a date OR a mileage answers
     * it, so it is one item, not two fields.
     *
     * client/lib/screens/fleet/vehicle_identity_card.dart draws these same
     * fields with `flagEmpty: true` (plate and plate state through
     * `notFilledIn`). Add one here and flag it there, or the number beside the
     * row and the amber boxes on the card disagree.
     */
    public const EXPECTED_FIELDS = [
        'year',
        'make',
        'model',
        'vin',
        'plate',
        'plate_state',
        'colour',
        'odometer_miles',
        'registration_expires_on',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The register of vans the company ran. Sold and returned stay on the row as '
            .'statuses rather than disappearing, because who held a vehicle when something '
            .'happened is still a question after the van is gone. Removing the organization '
            .'takes the row. Empty mistakes can still be hard-deleted by hand.'
        );
    }

    /** @use HasFactory<VehicleFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'vehicles';

    use HasAttachments;

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle): void {
            $vehicle->refreshLabel();
            if ($vehicle->vin === '') {
                $vehicle->vin = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'body_type' => VehicleBodyType::class,
            'fuel_type' => VehicleFuelType::class,
            'odometer_miles' => 'integer',
            'odometer_updated_at' => 'datetime',
            'next_service_on' => 'date',
            'next_service_miles' => 'integer',
            'registration_expires_on' => 'date',
            'inspection_expires_on' => 'date',
            'toll_flat_rate_cents' => 'integer',
            'status' => VehicleStatus::class,
        ];
    }

    public function refreshLabel(): void
    {
        $this->label = VehicleName::derive(
            $this->year, $this->make, $this->model, $this->plate, $this->vin,
        );
    }

    /** True when there is no ledger history and no photos — safe to hard-delete. */
    public function isEmpty(): bool
    {
        return ! $this->ownershipPeriods()->exists()
            && ! $this->insurancePolicies()->exists()
            && ! $this->assignments()->exists()
            && ! $this->serviceVisits()->exists()
            && ! $this->payments()->exists()
            && ! $this->attachments()->exists();
    }

    public function openOwnership(): ?VehicleOwnershipPeriod
    {
        return $this->ownershipPeriods()->whereNull('ended_on')->first();
    }

    public function openInsurance(): ?VehicleInsurancePolicy
    {
        return $this->insurancePolicies()->whereNull('ended_on')->first();
    }

    public function openAssignment(): ?VehicleAssignment
    {
        return $this->assignments()->whereNull('released_at')->first();
    }

    // ── relations ────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function ownershipPeriods(): HasMany
    {
        return $this->hasMany(VehicleOwnershipPeriod::class);
    }

    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(VehicleInsurancePolicy::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function serviceVisits(): HasMany
    {
        return $this->hasMany(VehicleServiceVisit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VehiclePayment::class);
    }
}
