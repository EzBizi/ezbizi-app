<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleEndedReason: string
{
    use HasValues;

    case Sold = 'sold';
    case Returned = 'returned';
    case Bought = 'bought';
    case Replaced = 'replaced';
    case Other = 'other';
}
