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
 * numbers and a date.
 */
class JobPart extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['order', 'stock'];

    protected $fillable = ['organization_id', 'job_id', 'kind', 'item', 'quantity', 'price_minor', 'delivery_on', 'inventory_item_id', 'position'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_minor' => 'integer', 'delivery_on' => 'date', 'position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A part is part of its job and goes with it.');
    }

    public function present(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'item' => $this->item, 'quantity' => $this->quantity,
            'price_minor' => $this->price_minor, 'delivery_on' => $this->delivery_on?->toDateString(),
            'inventory_item_id' => $this->inventory_item_id];
    }
}
