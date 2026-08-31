<?php

namespace App\Support\Tasks;

use App\Support\Enums\HasValues;

enum PriorityRank: string
{
    use HasValues;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
