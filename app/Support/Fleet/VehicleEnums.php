<?php

namespace App\Support\Fleet;

/**
 * Platform enums for Fleet Management. Values are the wire strings validation
 * (`Rule::in`) and the client both use — tenants do not invent these.
 */

enum VehicleStatus: string
{
    case Active = 'active';
    case InShop = 'in_shop';
    case OutOfService = 'out_of_service';
    case Sold = 'sold';
    case Returned = 'returned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehicleBodyType: string
{
    case Van = 'van';
    case Truck = 'truck';
    case Car = 'car';
    case Suv = 'suv';
    case Trailer = 'trailer';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehicleFuelType: string
{
    case Gas = 'gas';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

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

enum VehicleEndedReason: string
{
    case Sold = 'sold';
    case Returned = 'returned';
    case Bought = 'bought';
    case Replaced = 'replaced';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehicleServiceKind: string
{
    case Oil = 'oil';
    case Tires = 'tires';
    case Brakes = 'brakes';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Registration = 'registration';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

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

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehiclePaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Ach = 'ach';
    case Net30 = 'net_30';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehicleRatePeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehiclePremiumPeriod: string
{
    case Month = 'month';
    case SixMonths = 'six_months';
    case Year = 'year';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum VehicleRentalTerms: string
{
    case DueOnReceipt = 'due_on_receipt';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net45 = 'net_45';
    case Net60 = 'net_60';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
