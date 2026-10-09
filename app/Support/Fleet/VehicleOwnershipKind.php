<?php

namespace App\Support\Fleet;

enum VehicleOwnershipKind: string
{
    case Owned = 'owned';
    case Rented = 'rented';
}
