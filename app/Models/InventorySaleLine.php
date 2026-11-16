<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id', 'item_id', 'qty_ordered', 'qty_fulfilled', 'unit_price_minor',
])]
class InventorySaleLine extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A sale line hangs off the sale. Removing the sale takes the row.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A line is its sale's change on the live feed (RecordsParentChanges): the Orders tab lists sales. */
    protected static array $changeParent = ['inventory_orders', InventorySale::class, 'sale_id'];

    use HasUuids;

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_fulfilled' => 'decimal:3',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
