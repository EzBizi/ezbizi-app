<?php

namespace App\Policies;

use App\Models\InventoryIssue;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Who may post an issue (spec §8.4).
 *
 * From: the caller can update the location (inventory.edit + write reach).
 * Naming a job is not a Jobs write — view-scope is checked in the
 * controller, not here. Files on the issue travel with the location's
 * view / edit, the same way a service visit travels with its vehicle.
 */
class InventoryIssuePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventoryIssue $issue): Response
    {
        return Gate::forUser($user)->inspect('view', $issue->location);
    }

    public function create(User $user, InventoryLocation $location): Response
    {
        return app(InventoryLocationPolicy::class)->update($user, $location);
    }

    public function update(User $user, InventoryIssue $issue): Response
    {
        return Gate::forUser($user)->inspect('update', $issue->location);
    }
}
