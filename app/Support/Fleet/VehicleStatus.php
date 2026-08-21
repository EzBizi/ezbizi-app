<?php

namespace App\Support\Fleet;

enum VehicleStatus: string
{
    case Active = 'active';
    case InShop = 'in_shop';
    case OutOfService = 'out_of_service';
    case Sold = 'sold';
    case Returned = 'returned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
