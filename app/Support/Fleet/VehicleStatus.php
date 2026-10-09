<?php

namespace App\Support\Fleet;

use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use Illuminate\Validation\ValidationException;

enum VehicleStatus: string
{
    case Active = 'active';
    case InShop = 'in_shop';
    case OutOfService = 'out_of_service';
    case Sold = 'sold';
    case Returned = 'returned';


    public function isEnded(): bool
    {
        return $this === self::Sold || $this === self::Returned;
    }

    public function isOnRoad(): bool
    {
        return in_array($this, [self::Active, self::InShop, self::OutOfService], true);
    }

    /**
     * Move a vehicle to $next, closing or opening an ownership period the same
     * way the HTTP write does. A rule that marks a rental sold gets the same
     * 422 as a person saving.
     *
     * Sets status on the model; the caller saves the vehicle.
     *
     * @param  array<string, mixed>|null  $ownership
     */
    public static function apply(Vehicle $vehicle, self $next, ?array $ownership = null): void
    {
        $current = $vehicle->status;
        $becomingSold = $next === self::Sold && $current !== self::Sold;
        $becomingReturned = $next === self::Returned && $current !== self::Returned;
        $buyingBack = $current->isEnded() && $next->isOnRoad();
        $ownershipPresent = $ownership !== null;

        if ($becomingSold) {
            $open = $vehicle->openOwnership();
            if ($open === null || $open->kind !== VehicleOwnershipKind::Owned) {
                throw ValidationException::withMessages([
                    'status' => 'Sold is for a vehicle the company owns. Return a rental instead.',
                ]);
            }
        }

        if ($becomingReturned) {
            $open = $vehicle->openOwnership();
            if ($open === null || $open->kind !== VehicleOwnershipKind::Rented) {
                throw ValidationException::withMessages([
                    'status' => 'Return is for a rental. Mark an owned vehicle sold instead.',
                ]);
            }
        }

        if ($buyingBack && ! $ownershipPresent) {
            throw ValidationException::withMessages([
                'ownership' => 'A vehicle has to be owned or rented. Say which, then save.',
            ]);
        }

        if ($ownershipPresent && ! $buyingBack) {
            throw ValidationException::withMessages([
                'ownership' => 'A new ownership period belongs with bringing the vehicle back on the road.',
            ]);
        }

        if ($becomingSold) {
            self::closeOpenOwnership($vehicle, VehicleEndedReason::Sold);
        }

        if ($becomingReturned) {
            self::closeOpenOwnership($vehicle, VehicleEndedReason::Returned);
        }

        if ($buyingBack) {
            self::openOwnershipPeriod($vehicle, $ownership);
        }

        if ($next !== $current) {
            $vehicle->status = $next;
        }
    }

    private static function closeOpenOwnership(Vehicle $vehicle, VehicleEndedReason $reason): void
    {
        $open = $vehicle->openOwnership();
        if ($open === null) {
            return;
        }

        $open->ended_on = now()->toDateString();
        $open->ended_reason = $reason;
        $open->save();
    }

    /**
     * Kind, counterparty, started_on only. Cents, lienholder and loan_account
     * belong on the HTTP buy-back, which copies them after apply from the
     * vehicle validator. A rule payload must not plant money on the period.
     *
     * @param  array<string, mixed>  $ownership
     */
    private static function openOwnershipPeriod(Vehicle $vehicle, array $ownership): void
    {
        VehicleOwnershipPeriod::create([
            'vehicle_id' => $vehicle->getKey(),
            'kind' => $ownership['kind'],
            'counterparty' => $ownership['counterparty'] ?? null,
            'started_on' => $ownership['started_on'] ?? now()->toDateString(),
        ]);
    }
}
