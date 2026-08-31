<?php

namespace App\Support\Tasks;

use App\Support\Enums\HasValues;

enum BoardVisibility: string
{
    use HasValues;

    case Scope = 'scope';
    case Private = 'private';
}
