<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One receive against a purchase order, into one location. */
#[Fillable([
    'purchase_order_id', 'location_id', 'received_on', 'received_by',
])]
class InventoryReceive extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A receive hangs off its purchase order and is removed with it.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A receive is its purchase order's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['inventory_orders', InventoryPurchaseOrder::class, 'purchase_order_id'];

    use HasUuids;

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchaseOrder::class, 'purchase_order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReceiveLine::class, 'receive_id');
    }
}
