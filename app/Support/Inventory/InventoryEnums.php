<?php

namespace App\Support\Inventory;

use App\Support\Enums\HasValues;

enum InventoryUnit: string
{
    use HasValues;

    case Each = 'each';
    case Box = 'box';
    case Ft = 'ft';
    case In = 'in';
    case Gal = 'gal';
    case Lb = 'lb';
    case Kit = 'kit';
    case Other = 'other';
}

enum InventoryItemStatus: string
{
    use HasValues;

    case Active = 'active';
    case Discontinued = 'discontinued';
}

enum InventoryLocationKind: string
{
    use HasValues;

    case Warehouse = 'warehouse';
    case Shop = 'shop';
    case Cage = 'cage';
    case Receiving = 'receiving';
    case Van = 'van';
    case Other = 'other';
}

enum InventoryLocationStatus: string
{
    use HasValues;

    case Active = 'active';
    case Closed = 'closed';
}

enum InventoryPurchaseOrderStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Open = 'open';
    case Received = 'received';
    case Cancelled = 'cancelled';
}

enum InventorySaleStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Open = 'open';
    case Partial = 'partial';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}

enum InventorySalePaymentStatus: string
{
    use HasValues;

    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
