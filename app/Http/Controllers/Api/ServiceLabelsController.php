<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceLabel;

class ServiceLabelsController extends ServiceTaxonomyController
{
    protected function modelClass(): string
    {
        return ServiceLabel::class;
    }

    protected function noun(): string
    {
        return 'label';
    }
}
