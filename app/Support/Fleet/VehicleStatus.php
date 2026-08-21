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

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

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

    /** @param  array<string, mixed>  $ownership */
    private static function openOwnershipPeriod(Vehicle $vehicle, array $ownership): void
    {
        VehicleOwnershipPeriod::create([
            'vehicle_id' => $vehicle->getKey(),
            'kind' => $ownership['kind'],
            'counterparty' => $ownership['counterparty'] ?? null,
            'started_on' => $ownership['started_on'] ?? now()->toDateString(),
            'purchase_price_cents' => $ownership['purchase_price_cents'] ?? null,
            'lienholder' => $ownership['lienholder'] ?? null,
            'loan_account' => $ownership['loan_account'] ?? null,
            'loan_monthly_payment_cents' => $ownership['loan_monthly_payment_cents'] ?? null,
            'loan_payoff_date' => $ownership['loan_payoff_date'] ?? null,
            'transport_cost_cents' => $ownership['transport_cost_cents'] ?? null,
            'rental_rate_cents' => $ownership['rental_rate_cents'] ?? null,
            'rental_rate_period' => $ownership['rental_rate_period'] ?? null,
            'rental_deposit_cents' => $ownership['rental_deposit_cents'] ?? null,
            'rental_account_number' => $ownership['rental_account_number'] ?? null,
            'rental_payment_terms' => $ownership['rental_payment_terms'] ?? null,
            'rental_payment_method' => $ownership['rental_payment_method'] ?? null,
        ]);
    }
}
