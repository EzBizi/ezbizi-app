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

#[Fillable([
    'sale_id', 'location_id', 'fulfilled_on', 'fulfilled_by',
])]
class InventorySaleFulfillment extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A fulfillment hangs off the sale. Removing the sale takes the row.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A fulfilment is its sale's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['inventory_orders', InventorySale::class, 'sale_id'];

    use HasUuids;

    protected function casts(): array
    {
        return [
            'fulfilled_on' => 'date',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySaleFulfillmentLine::class, 'fulfillment_id');
    }
}
