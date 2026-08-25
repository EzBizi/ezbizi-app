<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a lead.
 *
 * Files the same way a service does — organization, company, or franchise —
 * so the same reach rules apply and a branch manager sees the enquiries their
 * branch's advertising produced rather than the whole group's.
 *
 * There is no `create`: nobody types a lead in. Every row arrives from a
 * connected advertising account, which is what makes it evidence of what a
 * member of the public actually asked for. The permission exists because
 * DEFAULT_ACTIONS carries it and the roles screen is clearer for having the
 * full set; nothing calls it.
 */
class LeadPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'leads.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a lead';
    }

    protected function seeAct(): string
    {
        return 'see this lead';
    }

    protected function changeAct(): string
    {
        return 'change this lead';
    }

    protected function deleteAct(): string
    {
        return 'delete this lead';
    }
}
