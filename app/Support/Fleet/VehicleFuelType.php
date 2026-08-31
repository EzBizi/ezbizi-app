<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleFuelType: string
{
    use HasValues;

    case Gas = 'gas';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Other = 'other';
}
