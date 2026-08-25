<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/** Who may do what to a service (spec). */
class ServicePolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'services.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a service';
    }

    protected function seeAct(): string
    {
        return 'see this service';
    }

    protected function changeAct(): string
    {
        return 'change this service';
    }

    protected function deleteAct(): string
    {
        return 'delete this service';
    }
}
