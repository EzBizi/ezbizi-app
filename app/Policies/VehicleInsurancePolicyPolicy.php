<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/** Insurance periods travel with the parent vehicle’s view / edit. */
class VehicleInsurancePolicyPolicy
{
    public function view(User $user, VehicleInsurancePolicy $policy): Response
    {
        return Gate::forUser($user)->inspect('view', $policy->vehicle);
    }

    public function create(User $user, Vehicle $vehicle): Response
    {
        return Gate::forUser($user)->inspect('update', $vehicle);
    }

    public function update(User $user, VehicleInsurancePolicy $policy): Response
    {
        return Gate::forUser($user)->inspect('update', $policy->vehicle);
    }

    public function delete(User $user, VehicleInsurancePolicy $policy): Response
    {
        return Gate::forUser($user)->inspect('update', $policy->vehicle);
    }
}
