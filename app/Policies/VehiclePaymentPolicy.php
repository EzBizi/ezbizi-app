<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePayment;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Payments and their receipts require `financials` to view (spec §8).
 *
 * Otherwise a receipt URL is a back door to the ledger.
 */
class VehiclePaymentPolicy
{
    public function view(User $user, VehiclePayment $payment): Response
    {
        $parent = Gate::forUser($user)->inspect('view', $payment->vehicle);

        if ($parent->denied()) {
            return $parent;
        }

        return $user->hasPermission('fleet_management.financials')
            ? Response::allow()
            : Response::deny('Amounts on the fleet are hidden for your role.');
    }

    public function create(User $user, Vehicle $vehicle): Response
    {
        $parent = Gate::forUser($user)->inspect('update', $vehicle);

        if ($parent->denied()) {
            return $parent;
        }

        return $user->hasPermission('fleet_management.financials')
            ? Response::allow()
            : Response::deny('Amounts on the fleet are hidden for your role.');
    }

    public function update(User $user, VehiclePayment $payment): Response
    {
        $parent = Gate::forUser($user)->inspect('update', $payment->vehicle);

        if ($parent->denied()) {
            return $parent;
        }

        return $user->hasPermission('fleet_management.financials')
            ? Response::allow()
            : Response::deny('Amounts on the fleet are hidden for your role.');
    }

    public function delete(User $user, VehiclePayment $payment): Response
    {
        return $this->update($user, $payment);
    }
}
