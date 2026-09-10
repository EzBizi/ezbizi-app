<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live app install that can be rung.
 *
 * A person may hold several: the desk on a PC, the app on a phone. Each gets
 * its own Voice identity so `<Dial>` can name them all — see the migration for
 * why one identity per person could only ever ring one of them.
 */
class PhoneDeviceRegistration extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Not dialled after this long without asking for a token. */
    public const STALE_AFTER_MINUTES = 90;

    protected $fillable = [
        'organization_id', 'connection_id', 'user_id',
        'device_id', 'label', 'platform', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * The shortest window this codebase sweeps on, and that is the point.
     *
     * ── Why this is a privacy decision, not housekeeping ─────────────
     *
     * A row here ties a named person to a particular handset. It stops being
     * USEFUL ninety minutes after that device last asked for a token — see
     * [STALE_AFTER_MINUTES], which is what decides whether it is dialled — so
     * everything kept beyond that is a record of which devices somebody owns,
     * held for no working reason.
     *
     * One month is the shortest window this policy can express, not a judgement
     * that a month is the right length. If the sweep ever takes a finer unit,
     * this should take it.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(1, 'last_seen_at',
            'One row per live app install, refreshed every time that install asks '
            .'for a Voice token. It stops being read 90 minutes after that, so what '
            .'remains is a record of whose handset is whose and is swept as soon as '
            .'this policy can express.'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    /**
     * Devices worth ringing for these people on this connection.
     *
     * Ordered oldest-seen last so that, if somebody has more installs than a
     * Dial can hold, the ones dropped are the ones least recently used rather
     * than whichever the database happened to return first.
     *
     * @param  list<string>  $userIds
     * @return \Illuminate\Database\Eloquent\Collection<int, PhoneDeviceRegistration>
     */
    public static function liveFor(string $connectionId, array $userIds)
    {
        if ($userIds === []) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->where('connection_id', $connectionId)
            ->whereIn('user_id', $userIds)
            ->where('last_seen_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->orderByDesc('last_seen_at')
            ->get();
    }
}
