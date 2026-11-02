<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a catalogue item (spec §4, §5).
 *
 * Reach is organization, then company + optional franchise. General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including franchise-only people assigned under it. Organization items
 * (`company_id` null) are visible throughout the tenant; writing them is an
 * organization act.
 */
class InventoryItemPolicy
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
        return 'add an item';
    }

    protected function seeAct(): string
    {
        return 'see this item';
    }

    protected function changeAct(): string
    {
        return 'change an item';
    }

    protected function deleteAct(): string
    {
        return 'delete an item';
    }
}
