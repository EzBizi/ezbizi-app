<?php

namespace App\Support\Tasks;

enum StatusCategory: string
{
    case NotStarted = 'not_started';
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
