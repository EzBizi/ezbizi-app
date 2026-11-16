<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\InventoryLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named place stock sits (spec §6).
 *
 * Soft-delete is not used. Closed is a status. Hard delete only when
 * isEmpty() and no movement still names the place.
 * `catalogue_key` is a generated stored column — never written.
 */
#[Fillable([
    'company_id', 'franchise_id', 'name', 'kind', 'status', 'vehicle_id',
])]
class InventoryLocation extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The places stock sat. Closed stays on the row rather than disappearing, '
            .'because a later movement still names the location. Removing the organization '
            .'takes the row. Empty mistakes can still be hard-deleted by hand.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'inventory_locations';

    /** @use HasFactory<InventoryLocationFactory> */
    use HasFactory;
    use HasUuids;

    protected static function booted(): void
    {
        static::saving(function (InventoryLocation $location): void {
            unset($location->catalogue_key);
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => InventoryLocationKind::class,
            'status' => InventoryLocationStatus::class,
        ];
    }

    /** True when no balance row holds a non-zero qty. */
    public function isEmpty(): bool
    {
        return ! $this->balances()->where('qty_on_hand', '!=', 0)->exists();
    }

    /**
     * True when an adjustment, transfer, issue, or receive still names
     * this place — not safe to hard-delete even at zero qty.
     */
    public function hasHistory(): bool
    {
        return $this->adjustments()->exists()
            || $this->issues()->exists()
            || $this->receives()->exists()
            || $this->transfersFrom()->exists()
            || $this->transfersTo()->exists();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'location_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class, 'location_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InventoryIssue::class, 'location_id');
    }

    public function receives(): HasMany
    {
        return $this->hasMany(InventoryReceive::class, 'location_id');
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'from_location_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'to_location_id');
    }
}
