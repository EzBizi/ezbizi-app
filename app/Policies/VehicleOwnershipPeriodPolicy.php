<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/** Ownership periods travel with the parent vehicle’s view / edit. */
class VehicleOwnershipPeriodPolicy
{
    public function view(User $user, VehicleOwnershipPeriod $period): Response
    {
        return Gate::forUser($user)->inspect('view', $period->vehicle);
    }

    public function create(User $user, Vehicle $vehicle): Response
    {
        return Gate::forUser($user)->inspect('update', $vehicle);
    }

    public function update(User $user, VehicleOwnershipPeriod $period): Response
    {
        return Gate::forUser($user)->inspect('update', $period->vehicle);
    }

    public function delete(User $user, VehicleOwnershipPeriod $period): Response
    {
        return Gate::forUser($user)->inspect('update', $period->vehicle);
    }
}
