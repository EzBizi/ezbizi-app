<?php

namespace App\Support\Fleet;

enum VehiclePremiumPeriod: string
{
    case Month = 'month';
    case SixMonths = 'six_months';
    case Year = 'year';
}
