<?php

namespace App\Support\Fleet;

enum VehicleOwnershipKind: string
{
    case Owned = 'owned';
    case Rented = 'rented';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
