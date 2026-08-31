<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleBodyType: string
{
    use HasValues;

    case Van = 'van';
    case Truck = 'truck';
    case Car = 'car';
    case Suv = 'suv';
    case Trailer = 'trailer';
    case Other = 'other';
}
