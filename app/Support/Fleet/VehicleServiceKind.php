<?php

namespace App\Support\Fleet;

enum VehicleServiceKind: string
{
    case Oil = 'oil';
    case Tires = 'tires';
    case Brakes = 'brakes';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Registration = 'registration';
    case Other = 'other';
}
