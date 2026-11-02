<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a stock location (spec §4, §6).
 *
 * Reach is organization, then company + optional franchise. General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including franchise-only people assigned under it. Organization locations
 * (`company_id` null) are visible throughout the tenant; writing them is an
 * organization act.
 */
class InventoryLocationPolicy
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
        return 'add a location';
    }

    protected function seeAct(): string
    {
        return 'see this location';
    }

    protected function changeAct(): string
    {
        return 'change a location';
    }

    protected function deleteAct(): string
    {
        return 'delete a location';
    }
}
