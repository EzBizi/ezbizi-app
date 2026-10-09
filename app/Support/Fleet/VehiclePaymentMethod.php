<?php

namespace App\Support\Fleet;

enum VehiclePaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Ach = 'ach';
    case Net30 = 'net_30';
    case Other = 'other';
}
