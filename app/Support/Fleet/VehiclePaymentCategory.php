<?php

namespace App\Support\Fleet;

enum VehiclePaymentCategory: string
{
    case Purchase = 'purchase';
    case Loan = 'loan';
    case Registration = 'registration';
    case Insurance = 'insurance';
    case Maintenance = 'maintenance';
    case Rental = 'rental';
    case Toll = 'toll';
    case Deposit = 'deposit';
    case Other = 'other';
}
