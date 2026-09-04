<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehiclePremiumPeriod: string
{
    use HasValues;

    case Month = 'month';
    case SixMonths = 'six_months';
    case Year = 'year';
}
