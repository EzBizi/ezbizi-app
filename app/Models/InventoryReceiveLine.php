<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qty received this time against one purchase-order line. */
#[Fillable([
    'receive_id', 'purchase_order_line_id', 'qty',
])]
class InventoryReceiveLine extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A receive line hangs off its receive and is removed with it.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function receive(): BelongsTo
    {
        return $this->belongsTo(InventoryReceive::class, 'receive_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
