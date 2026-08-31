<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleServiceKind: string
{
    use HasValues;

    case Oil = 'oil';
    case Tires = 'tires';
    case Brakes = 'brakes';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Registration = 'registration';
    case Other = 'other';
}
