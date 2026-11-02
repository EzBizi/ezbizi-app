<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a vendor (spec §8.3).
 *
 * Same tree and place-write as catalogue items: organization, company, or
 * franchise. General is visible to anyone who can see that company.
 */
class InventoryVendorPolicy
{
    use AuthorizesCatalogPlace;

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
        return 'add a vendor';
    }

    protected function seeAct(): string
    {
        return 'see this vendor';
    }

    protected function changeAct(): string
    {
        return 'change a vendor';
    }

    protected function deleteAct(): string
    {
        return 'delete a vendor';
    }
}
