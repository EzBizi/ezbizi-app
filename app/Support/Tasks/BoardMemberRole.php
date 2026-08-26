<?php

namespace App\Support\Tasks;

enum BoardMemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
