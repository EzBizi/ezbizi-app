<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryUnit;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One catalogue row (spec §5).
 *
 * Display is `{sku} · {name}` when sku is set. Soft-delete is not used:
 * discontinued stays; hard delete only when isEmpty().
 * `catalogue_key` is a generated stored column — never written.
 */
#[Fillable([
    'company_id', 'franchise_id', 'number', 'sku', 'name', 'unit', 'status',
    'reorder_at', 'last_cost_minor',
])]
class InventoryItem extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The catalogue of what the company kept. Discontinued stays on the row as a '
            .'status rather than disappearing, because a later movement still names the '
            .'item. Removing the organization takes the row. Empty mistakes can still be '
            .'hard-deleted by hand.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'inventory';

    use HasAttachments;
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;
    use HasUuids;

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item): void {
            if ($item->sku === '') {
                $item->sku = null;
            }
            unset($item->catalogue_key);
        });
    }

    protected function casts(): array
    {
        return [
            'unit' => InventoryUnit::class,
            'status' => InventoryItemStatus::class,
            'reorder_at' => 'decimal:3',
            'last_cost_minor' => 'integer',
        ];
    }

    public function displayName(): string
    {
        return $this->sku === null || $this->sku === ''
            ? $this->name
            : $this->sku.' · '.$this->name;
    }

    /**
     * True when there is no on-hand and no order or movement lines —
     * safe to hard-delete.
     */
    public function isEmpty(): bool
    {
        return ! $this->balances()->where('qty_on_hand', '!=', 0)->exists()
            && ! $this->purchaseOrderLines()->exists()
            && ! $this->issueLines()->exists()
            && ! $this->transferLines()->exists()
            && ! $this->adjustments()->exists();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'item_id');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(InventoryPurchaseOrderLine::class, 'item_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class, 'item_id');
    }

    public function transferLines(): HasMany
    {
        return $this->hasMany(InventoryTransferLine::class, 'item_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class, 'item_id');
    }
}
