<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'fulfillment_id', 'sale_line_id', 'qty',
])]
class InventorySaleFulfillmentLine extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A fulfillment line hangs off the fulfillment.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(InventorySaleFulfillment::class, 'fulfillment_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(InventorySaleLine::class, 'sale_line_id');
    }
}
