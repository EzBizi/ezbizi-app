<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleName;
use App\Support\Fleet\VehicleStatus;
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
    'company_id', 'franchise_id', 'number', 'year', 'make', 'model', 'plate',
    'vin', 'colour', 'body_type', 'fuel_type', 'odometer_miles',
    'next_service_on', 'next_service_miles', 'status',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
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
            'next_service_on' => 'date',
            'next_service_miles' => 'integer',
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
