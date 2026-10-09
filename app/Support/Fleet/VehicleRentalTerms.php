<?php

namespace App\Support\Fleet;

enum VehicleRentalTerms: string
{
    case DueOnReceipt = 'due_on_receipt';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net45 = 'net_45';
    case Net60 = 'net_60';
}
