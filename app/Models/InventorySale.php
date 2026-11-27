<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sales order (spec §8.5). Stock leaves only when a fulfillment posts. */
#[Fillable([
    'company_id', 'franchise_id', 'customer_id', 'number', 'status',
    'payment_status', 'note', 'sold_on',
])]
class InventorySale extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What was sold, to whom, and whether it left the shelf. Posted '
            .'fulfillments are the audit. Removing the organization takes the row.'
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
            'status' => InventorySaleStatus::class,
            'payment_status' => InventorySalePaymentStatus::class,
            'sold_on' => 'date',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class, 'sale_id');
    }
}
