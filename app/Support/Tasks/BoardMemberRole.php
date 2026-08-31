<?php

namespace App\Support\Tasks;

use App\Support\Enums\HasValues;

enum BoardMemberRole: string
{
    use HasValues;

    case Owner = 'owner';
    case Member = 'member';
}
