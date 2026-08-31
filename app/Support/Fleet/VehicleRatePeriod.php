<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleRatePeriod: string
{
    use HasValues;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
