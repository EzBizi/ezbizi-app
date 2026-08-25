<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceGroup;

class ServiceGroupsController extends ServiceTaxonomyController
{
    protected function modelClass(): string
    {
        return ServiceGroup::class;
    }

    protected function noun(): string
    {
        return 'group';
    }
}
