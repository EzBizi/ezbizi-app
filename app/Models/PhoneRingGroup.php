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
 * Who rings, and how, when a number is answered by a group.
 */
class PhoneRingGroup extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The strings TwimlRenderer and DialPlan already branch on.
     *
     * Named here rather than repeated as literals in the controller, because
     * the renderer compares against these exact words when it builds the
     * <Dial> — a validator that accepted a fifth spelling would save a group
     * that renders as silence.
     *
     * @see \App\Support\Phone\TwimlRenderer
     */
    public const MODE_SIMULTANEOUS = 'simultaneous';

    public const MODE_SEQUENTIAL = 'sequential';

    public const MODES = [self::MODE_SIMULTANEOUS, self::MODE_SEQUENTIAL];

    public const OVERFLOW_VOICEMAIL = 'voicemail';

    public const OVERFLOW_FORWARD = 'forward';

    public const OVERFLOW_GROUP = 'group';

    /** Hold the caller in a line rather than answering with a machine. */
    public const OVERFLOW_QUEUE = 'queue';

    public const OVERFLOWS = [
        self::OVERFLOW_VOICEMAIL,
        self::OVERFLOW_FORWARD,
        self::OVERFLOW_GROUP,
        self::OVERFLOW_QUEUE,
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Ring groups for a connection. Live dial-plan config, removed when deleted or '
            .'when the connection goes.'
        );
    }

    protected $fillable = [
        'scope_type', 'scope_id',
        'organization_id', 'connection_id', 'name', 'mode', 'timeout_seconds',
        'member_user_ids', 'member_role_ids',
        'overflow', 'overflow_group_id', 'overflow_number',
        'queue_max_seconds', 'queue_announce_position', 'queue_announce_wait',
        'queue_hold_music_url',
    ];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'member_user_ids' => 'array',
            'member_role_ids' => 'array',
            'queue_max_seconds' => 'integer',
            'queue_announce_position' => 'boolean',
            'queue_announce_wait' => 'boolean',
        ];
    }

    /**
     * What Twilio calls this group's queue.
     *
     * Twilio creates a queue the first time an `<Enqueue>` names it and finds
     * it again by that name, so the name IS the identity — two groups sharing
     * one would put their callers in the same line, and a name derived from
     * anything a person can edit would strand everybody already waiting the
     * moment somebody renamed the group.
     */
    public function queueName(): string
    {
        return 'ezbizi-'.$this->id;
    }

    /**
     * Where the caller's hold music comes from.
     *
     * The fallback is one of the five files in Twilio's own default hold
     * playlist (`s3.amazonaws.com/com.twilio.sounds.music/index.xml`), read
     * from that playlist rather than guessed, and reached through the
     * path-style S3 host — the bucket-style hostname has dots in it and fails
     * TLS, which is the sort of thing that only shows up on a live call.
     */
    public function holdMusicUrl(): string
    {
        $own = trim((string) $this->queue_hold_music_url);

        return $own !== ''
            ? $own
            : 'https://s3.amazonaws.com/com.twilio.sounds.music/ClockworkWaltz.mp3';
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
