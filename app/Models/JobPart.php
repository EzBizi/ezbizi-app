<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A part a job needs (Jose, 2026-11-10): "order" (item, quantity, price,
 * delivery date) or "stock" (item, quantity, price). Shown summed up in
 * Project Management's Part Status column. No personal data: an item name,
 * numbers, a date, and the supplier business it comes from.
 *
 * The parts selector (Jose, 2026-11-17) added "pickup" (a same-day pickup
 * store), the supplier as picked, where it goes (warehouse, customer,
 * supplier), and when it was ordered and received.
 */
class JobPart extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['order', 'stock', 'pickup'];

    /** Not received this many days after it was ordered: red, like a lead (Jose, 2026-11-17). */
    public const LATE_AFTER_DAYS = 3;

    protected $fillable = ['organization_id', 'job_id', 'kind', 'item', 'quantity', 'price_minor', 'delivery_on', 'inventory_item_id', 'position',
        'supplier', 'destination', 'ordered_at', 'received_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_minor' => 'integer', 'delivery_on' => 'date', 'position' => 'integer',
            'supplier' => 'array', 'ordered_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A part is part of its job and goes with it.');
    }

    public function present(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'item' => $this->item, 'quantity' => $this->quantity,
            'price_minor' => $this->price_minor, 'delivery_on' => $this->delivery_on?->toDateString(),
            'inventory_item_id' => $this->inventory_item_id, 'supplier' => $this->supplier, 'destination' => $this->destination,
            'ordered_at' => $this->ordered_at?->toIso8601String(), 'received_at' => $this->received_at?->toIso8601String(),
            'late' => $this->isLate()];
    }

    /** Ordered or picked up, not received, for three days or more. */
    public function isLate(): bool
    {
        return $this->ordered_at !== null && $this->received_at === null
            && $this->ordered_at->lte(now()->subDays(self::LATE_AFTER_DAYS));
    }
}
