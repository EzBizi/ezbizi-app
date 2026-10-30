<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a purchase order (spec §8.3).
 *
 * Place-write matches items: creating a draft is inventory.create at a place
 * the caller can write. Opening, cancelling, and receiving travel with
 * inventory.edit.
 */
class InventoryPurchaseOrderPolicy
{
    // There was never a delete here. Private keeps the Gate refusing it the
    // way it refused the missing method.
    use AuthorizesCatalogPlace {
        delete as private;
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
        return 'add a purchase order';
    }

    protected function seeAct(): string
    {
        return 'see this order';
    }

    protected function changeAct(): string
    {
        return 'change an order';
    }

    protected function deleteAct(): string
    {
        return 'delete an order';
    }
}
