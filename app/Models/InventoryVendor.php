<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\InventoryVendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who we buy from (spec §8.3).
 *
 * Phone and email are the supplier's contact, not a person in the §9 inventory.
 * Delete only when no purchase order has named them.
 */
#[Fillable([
    'company_id', 'franchise_id', 'name', 'phone', 'email',
])]
class InventoryVendor extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Who the company bought from. A later PO still names the vendor. Removing '
            .'the organization takes the row. Vendors never named on a PO can still be '
            .'hard-deleted by hand.'
        );
    }

    use BelongsToTenant;

    use HasAttachments;
    /** @use HasFactory<InventoryVendorFactory> */
    use HasFactory;
    use HasUuids;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(InventoryPurchaseOrder::class, 'vendor_id');
    }
}
