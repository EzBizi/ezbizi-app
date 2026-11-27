<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item on a purchase order. */
#[Fillable([
    'purchase_order_id', 'item_id', 'qty_ordered', 'qty_received', 'unit_cost_minor',
])]
class InventoryPurchaseOrderLine extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A line hangs off its purchase order and is removed with it.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A line is its purchase order's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['inventory_orders', InventoryPurchaseOrder::class, 'purchase_order_id'];

    use HasUuids;

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'unit_cost_minor' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
