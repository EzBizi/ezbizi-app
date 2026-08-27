<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a Business Profile listing.
 *
 * Files like a service — organization, company, or franchise — so a branch
 * manager sees the listings their branch is accountable for.
 *
 * `create` and `delete` exist because DEFAULT_ACTIONS carries them and the
 * roles screen reads better for the full set, but nothing calls either: a
 * listing is created on Google, not here, and deleting one from EzBizi would
 * say nothing to Google while quietly hiding a shop front from the person
 * responsible for it. Disconnecting the profile is what removes them.
 */
class BusinessLocationPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'business_profile.'.$action;
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
        return 'change this location';
    }

    protected function deleteAct(): string
    {
        return 'remove this location';
    }
}
