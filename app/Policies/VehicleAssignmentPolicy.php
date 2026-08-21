<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Handing keys uses `assign`, not `edit` (spec §6.3, §9).
 *
 * Reading who has the keys is ordinary vehicle view.
 */
class VehicleAssignmentPolicy
{
    public function view(User $user, VehicleAssignment $assignment): Response
    {
        return Gate::forUser($user)->inspect('view', $assignment->vehicle);
    }

    public function create(User $user, Vehicle $vehicle): Response
    {
        return Gate::forUser($user)->inspect('assign', $vehicle);
    }

    public function update(User $user, VehicleAssignment $assignment): Response
    {
        return Gate::forUser($user)->inspect('assign', $assignment->vehicle);
    }

    public function delete(User $user, VehicleAssignment $assignment): Response
    {
        return Gate::forUser($user)->inspect('assign', $assignment->vehicle);
    }
}
