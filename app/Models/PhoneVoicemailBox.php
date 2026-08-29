<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voicemail box: whose it is, and what a caller hears.
 *
 * Holds no messages. The recordings themselves live on phone_calls, where they
 * already have a retention policy — this is the greeting and the settings, so
 * deleting a box silences a line, it does not erase anything a caller said.
 */
class PhoneVoicemailBox extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const OWNER_COMPANY = 'company';

    public const OWNER_USER = 'user';

    public const OWNER_RING_GROUP = 'ring_group';

    public const OWNER_NUMBER = 'number';

    public const OWNERS = [
        self::OWNER_COMPANY,
        self::OWNER_USER,
        self::OWNER_RING_GROUP,
        self::OWNER_NUMBER,
    ];

    protected $fillable = [
        'organization_id', 'connection_id', 'name',
        'owner_type', 'owner_id',
        'greeting_mode', 'greeting_text', 'greeting_voice',
        'greeting_language', 'greeting_audio_url',
        'max_seconds', 'transcribe', 'notify_emails',
    ];

    /**
     * The same defaults the columns carry, stated here as well.
     *
     * Not belt and braces: a model built by create() and returned straight to
     * the caller has never been read back, so a DB-only default is NULL in
     * memory. That made a freshly created box report the built-in greeting
     * instead of its own — right in the database, wrong in the response, and
     * wrong in exactly the way somebody would trust.
     */
    protected $attributes = [
        'owner_type' => self::OWNER_COMPANY,
        'greeting_mode' => 'tts',
        'greeting_language' => 'en-US',
        'max_seconds' => 300,
        'transcribe' => false,
    ];

    protected function casts(): array
    {
        return [
            'transcribe' => 'boolean',
            'max_seconds' => 'integer',
            'notify_emails' => 'array',
        ];
    }

    /**
     * ── Why with the parent ──────────────────────────────────────
     *
     * A box is configuration, not content: a name, a greeting, a length and a
     * list of addresses to notify. The messages callers actually left are
     * recordings on phone_calls and carry their own policy there.
     *
     * The one piece of personal data here is the notify list, and it belongs
     * to the business rather than to a customer — it is who to tell, not who
     * called. It goes when the phone account goes, by cascade.
     *
     * A sweep window would be wrong outright: a greeting does not age, and
     * expiring one on a timer would silently return every line to the generic
     * "please leave a message" without anybody being told.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A voicemail box: its name, the greeting callers hear, how long a '
            .'message may be, and which email addresses are told about one. It '
            .'holds no messages — those are recordings kept with the call. It '
            .'is deleted with the phone account it belongs to.'
        );
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    /** What a caller actually hears, or null when the box speaks nothing of its own. */
    public function spokenGreeting(): ?string
    {
        if ($this->greeting_mode !== 'tts') {
            return null;
        }

        $text = trim((string) $this->greeting_text);

        return $text === '' ? null : $text;
    }
}
