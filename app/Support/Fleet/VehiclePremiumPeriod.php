<?php

namespace App\Support\Fleet;

enum VehiclePremiumPeriod: string
{
    case Month = 'month';
    case SixMonths = 'six_months';
    case Year = 'year';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
