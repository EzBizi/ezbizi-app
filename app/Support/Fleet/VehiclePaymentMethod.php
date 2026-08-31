<?php

namespace App\Support\Fleet;

use App\Support\Enums\HasValues;

enum VehiclePaymentMethod: string
{
    use HasValues;

    case Cash = 'cash';
    case Check = 'check';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Ach = 'ach';
    case Net30 = 'net_30';
    case Other = 'other';
}
