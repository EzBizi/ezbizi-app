<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A purchase order (spec §8.3). Posted status is the history; lines freeze once open. */
#[Fillable([
    'company_id', 'franchise_id', 'vendor_id', 'number', 'status', 'ordered_on',
])]
class InventoryPurchaseOrder extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What was ordered, from whom, and whether it arrived. Posted movements are '
            .'the audit. Removing the organization takes the row.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'inventory_orders';
    use HasAttachments;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => InventoryPurchaseOrderStatus::class,
            'ordered_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(InventoryVendor::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryPurchaseOrderLine::class, 'purchase_order_id');
    }

    public function receives(): HasMany
    {
        return $this->hasMany(InventoryReceive::class, 'purchase_order_id');
    }
}
