<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may post a receive (spec §8.3).
 *
 * The caller must be able to change the purchase order (inventory.edit +
 * write reach) and update the destination location. Closed and over-receive
 * are mover refusals, not reach.
 */
class InventoryReceivePolicy
{
    public function create(
        User $user,
        InventoryPurchaseOrder $order,
        InventoryLocation $location,
    ): Response {
        $orderWrite = app(InventoryPurchaseOrderPolicy::class)->update($user, $order);

        if ($orderWrite->denied()) {
            return $orderWrite;
        }

        return app(InventoryLocationPolicy::class)->update($user, $location);
    }
}
