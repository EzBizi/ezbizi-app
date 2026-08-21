<?php

namespace App\Support\Fleet;

enum VehicleRatePeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
