<?php

namespace App\Support\Tasks;

enum BoardVisibility: string
{
    case Scope = 'scope';
    case Private = 'private';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
