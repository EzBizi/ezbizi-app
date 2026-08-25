<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current Voice SDK presence for one person on one connection.
 *
 * One row per (connection, user), overwritten — not a log.
 */
class PhonePresence extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const CREATED_AT = null;

    public const STATE_REGISTERED = 'registered';
    public const STATE_DND = 'dnd';
    public const STATE_ON_CALL = 'on_call';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One current row per person per connection; it is replaced, not appended.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'user_id', 'state',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
