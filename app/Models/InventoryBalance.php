<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qty on hand for one item at one location (spec §7). */
#[Fillable([
    'item_id', 'location_id', 'qty_on_hand',
])]
class InventoryBalance extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'On-hand hangs off the item and the location. Removing either takes the '
            .'row. A missing row is zero, not a record that ages out.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    /** Stock on hand is the item's row on the Inventory list: a balance write is the item's change. */
    protected static function booted(): void
    {
        $feed = function (self $b) {
            if ($b->item_id !== null) {
                \App\Support\Live\ChangeFeed::record(InventoryItem::withoutGlobalScopes()->find($b->item_id, ['id', 'organization_id', 'company_id', 'franchise_id'])
                    ?? (new InventoryItem)->forceFill(['id' => $b->item_id, 'organization_id' => $b->organization_id]), 'inventory');
            }
        };
        static::saved($feed);
        static::deleted($feed);
    }

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:3',
        ];
    }

    /** On-hand as a decimal string. No row is zero. */
    public static function qtyOnHand(string $itemId, string $locationId): string
    {
        $balance = static::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->first();

        return $balance === null ? '0.000' : (string) $balance->qty_on_hand;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
