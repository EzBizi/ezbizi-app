<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleOwnershipKind: string
{
    use HasValues;

    case Owned = 'owned';
    case Rented = 'rented';
}
