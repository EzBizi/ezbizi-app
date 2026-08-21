<?php

namespace App\Support\Fleet;

enum VehicleEndedReason: string
{
    case Sold = 'sold';
    case Returned = 'returned';
    case Bought = 'bought';
    case Replaced = 'replaced';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
