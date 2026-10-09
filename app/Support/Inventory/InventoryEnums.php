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
}

enum InventoryItemStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';
}

enum InventoryLocationKind: string
{
    case Warehouse = 'warehouse';
    case Shop = 'shop';
    case Cage = 'cage';
    case Receiving = 'receiving';
    case Van = 'van';
    case Other = 'other';
}

enum InventoryLocationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}

enum InventoryPurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Received = 'received';
    case Cancelled = 'cancelled';
}

enum InventorySaleStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Partial = 'partial';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}

enum InventorySalePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
