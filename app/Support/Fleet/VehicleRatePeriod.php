<?php

namespace App\Support\Fleet;

enum VehicleRatePeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
