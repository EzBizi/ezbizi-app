<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleServiceVisit;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/** Service visits travel with the parent vehicle’s view / edit. */
class VehicleServiceVisitPolicy
{
    public function view(User $user, VehicleServiceVisit $visit): Response
    {
        return Gate::forUser($user)->inspect('view', $visit->vehicle);
    }

    public function create(User $user, Vehicle $vehicle): Response
    {
        return Gate::forUser($user)->inspect('update', $vehicle);
    }

    public function update(User $user, VehicleServiceVisit $visit): Response
    {
        return Gate::forUser($user)->inspect('update', $visit->vehicle);
    }

    public function delete(User $user, VehicleServiceVisit $visit): Response
    {
        return Gate::forUser($user)->inspect('update', $visit->vehicle);
    }
}
