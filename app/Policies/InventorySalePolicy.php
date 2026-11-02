<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalogPlace;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a sales order (spec §8.5).
 *
 * Creating a draft is inventory.create at a place the caller can write.
 * Opening, cancelling, paying, and fulfilling travel with inventory.edit.
 */
class InventorySalePolicy
{
    // There was never a delete here. Private keeps the Gate refusing it the
    // way it refused the missing method.
    use AuthorizesCatalogPlace {
        delete as private;
    }

    public function fulfill(User $user, InventorySale $sale, InventoryLocation $location): Response
    {
        $saleWrite = $this->update($user, $sale);

        if ($saleWrite->denied()) {
            return $saleWrite;
        }

        return app(InventoryLocationPolicy::class)->update($user, $location);
    }

    protected function permission(string $action): string
    {
        return 'inventory.'.$action;
    }

    protected function seeAnyAct(): string
    {
        return 'see inventory';
    }

    protected function addAct(): string
    {
        return 'add a sale';
    }

    protected function seeAct(): string
    {
        return 'see this sale';
    }

    protected function changeAct(): string
    {
        return 'change a sale';
    }

    protected function deleteAct(): string
    {
        return 'delete a sale';
    }
}
