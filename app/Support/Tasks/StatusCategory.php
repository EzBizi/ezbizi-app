<?php

namespace App\Support\Tasks;

use App\Support\Enums\HasValues;

enum StatusCategory: string
{
    use HasValues;

    case NotStarted = 'not_started';
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
