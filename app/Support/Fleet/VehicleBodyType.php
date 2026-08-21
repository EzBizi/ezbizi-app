<?php

namespace App\Support\Fleet;

enum VehicleBodyType: string
{
    case Van = 'van';
    case Truck = 'truck';
    case Car = 'car';
    case Suv = 'suv';
    case Trailer = 'trailer';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
