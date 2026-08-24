<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may post an issue (spec §8.4).
 *
 * From: the caller can update the location (inventory.edit + write reach).
 * Naming a job is not a Jobs write — view-scope is checked in the
 * controller, not here.
 */
class InventoryIssuePolicy
{
    public function create(User $user, InventoryLocation $location): Response
    {
        return app(InventoryLocationPolicy::class)->update($user, $location);
    }
}
