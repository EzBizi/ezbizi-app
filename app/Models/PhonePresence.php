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

    /**
     * Just off a call, and finishing it.
     *
     * Paired with `wrap_up_until`, and it is the TIME that decides — see
     * [available()]. A wrap-up that had to be cleared by something is a
     * wrap-up that gets stuck, and somebody stuck in it is somebody the ring
     * group quietly stops calling.
     */
    public const STATE_WRAP_UP = 'wrap_up';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One current row per person per connection; it is replaced, not appended.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'user_id', 'state', 'wrap_up_until',
    ];

    protected function casts(): array
    {
        return ['wrap_up_until' => 'datetime'];
    }

    /**
     * Can a call be sent to this person right now?
     *
     * Registered, or in a wrap-up whose deadline has passed. The second half
     * is what makes the feature safe: nothing has to run to end a wrap-up, so
     * there is no job to fail, no client callback to miss, and no way to leave
     * somebody unreachable because a timer did not fire.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<PhonePresence>  $query
     */
    public function scopeAvailable($query)
    {
        return $query->where(function ($q) {
            $q->where('state', self::STATE_REGISTERED)
                ->orWhere(function ($wrap) {
                    $wrap->where('state', self::STATE_WRAP_UP)
                        ->where(function ($deadline) {
                            $deadline->whereNull('wrap_up_until')
                                ->orWhere('wrap_up_until', '<=', now());
                        });
                });
        });
    }

    /** Still finishing the last call, as of now. */
    public function isWrappingUp(): bool
    {
        return $this->state === self::STATE_WRAP_UP
            && $this->wrap_up_until !== null
            && $this->wrap_up_until->isFuture();
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
