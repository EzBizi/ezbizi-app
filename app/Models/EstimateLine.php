<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an estimate: a service, a quantity and a price. */
#[Fillable([
    'estimate_id', 'service_id', 'name', 'description', 'quantity', 'unit_price_minor', 'position',
])]
class EstimateLine extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A line of an estimate: a service and a price, and nothing about any person. '
            .'Removing the estimate takes it.'
        );
    }

    protected function casts(): array
    {
        return [
            'quantity'         => 'decimal:2',
            'unit_price_minor' => 'integer',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }
}
