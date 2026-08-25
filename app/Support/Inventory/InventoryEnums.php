<?php

namespace App\Support\Inventory;

enum InventoryUnit: string
{
    case Each = 'each';
    case Box = 'box';
    case Ft = 'ft';
    case In = 'in';
    case Gal = 'gal';
    case Lb = 'lb';
    case Kit = 'kit';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventoryItemStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventoryLocationKind: string
{
    case Warehouse = 'warehouse';
    case Shop = 'shop';
    case Cage = 'cage';
    case Receiving = 'receiving';
    case Van = 'van';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventoryLocationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventoryPurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventorySaleStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Partial = 'partial';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

enum InventorySalePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
