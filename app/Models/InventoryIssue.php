<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock leaving a location (spec §8.4).
 *
 * `job_id` XOR a required note. Posted on save.
 */
#[Fillable([
    'location_id', 'job_id', 'note', 'number', 'issued_on', 'user_id',
])]
class InventoryIssue extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How stock left. Posted movements are the audit. Removing the organization '
            .'takes the row.'
        );
    }

    use BelongsToTenant;
    use HasAttachments;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class, 'issue_id');
    }
}
