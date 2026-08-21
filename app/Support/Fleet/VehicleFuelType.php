<?php

namespace App\Support\Fleet;

enum VehicleFuelType: string
{
    case Gas = 'gas';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
