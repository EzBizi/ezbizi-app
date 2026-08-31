<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehicleRentalTerms: string
{
    use HasValues;

    case DueOnReceipt = 'due_on_receipt';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net45 = 'net_45';
    case Net60 = 'net_60';
}
