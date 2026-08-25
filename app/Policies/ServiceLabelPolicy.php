<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/** Who may do what to a service label (spec). */
class ServiceLabelPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'services.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a label';
    }

    protected function seeAct(): string
    {
        return 'see this label';
    }

    protected function changeAct(): string
    {
        return 'change this label';
    }

    protected function deleteAct(): string
    {
        return 'delete this label';
    }
}
