<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item on an issue. */
#[Fillable([
    'issue_id', 'item_id', 'qty',
])]
class InventoryIssueLine extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'An issue line hangs off its issue and is removed with it.'
        );
    }

    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A line is its issue's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['inventory_orders', InventoryIssue::class, 'issue_id'];

    use HasUuids;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(InventoryIssue::class, 'issue_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
