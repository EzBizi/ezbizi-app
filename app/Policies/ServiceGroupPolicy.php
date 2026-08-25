<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/** Who may do what to a service group (spec). */
class ServiceGroupPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'services.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a group';
    }

    protected function seeAct(): string
    {
        return 'see this group';
    }

    protected function changeAct(): string
    {
        return 'change this group';
    }

    protected function deleteAct(): string
    {
        return 'delete this group';
    }
}
