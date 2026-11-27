<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Phone\TwimlRenderer;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\PhoneNumberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Twilio number hung on org / company / franchise (spec §4).
 *
 * Reach is not cascade: a franchise number does not inherit company lines.
 */
class PhoneNumber extends Model implements StatesRetention
{
    use BelongsToTenant;

    /** @use HasFactory<PhoneNumberFactory> */
    use HasFactory;

    use HasUuids;

    /** Outbound SMS is blocked until the line's campaign (or toll-free check) is approved; Settings flips sms_outbound_allowed. */
    public const SMS_REFUSAL = 'This line cannot send texts until messaging is approved.';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_FRANCHISE = 'franchise';

    /**
     * What a line does once the schedule says it is closed (spec 14).
     *
     * These exact strings are what TwimlRenderer branches on when it builds the
     * closed-hours document, so a validator that accepted a fourth spelling
     * would save a line that answers with silence.
     *
     * @see TwimlRenderer
     */
    public const AFTER_HOURS_VOICEMAIL = 'voicemail';

    public const AFTER_HOURS_FORWARD = 'forward';

    public const AFTER_HOURS_CLOSED = 'closed';

    public const AFTER_HOURS = [
        self::AFTER_HOURS_VOICEMAIL,
        self::AFTER_HOURS_FORWARD,
        self::AFTER_HOURS_CLOSED,
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A live phone line the organization has bought or ported. Held while the number '
            .'is on the account; releasing it deletes the row. Call and SMS history hang off '
            .'other tables with their own windows.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'scope_type', 'scope_id',
        'twilio_sid', 'account_sid', 'e164', 'label',
        'flow_id', 'hours_schedule_id', 'ring_group_id', 'voicemail_box_id',
        'answer_with', 'answer_user_id',
        'record', 'transcribe',
        'after_hours', 'after_hours_number', 'language',
        'messaging_service_sid', 'sms_outbound_allowed', 'lead_line', 'emergency_address_set',
        'emergency_address_sid', 'voice_caller_id_lookup', 'voicemail_transcribe',
        'capabilities', 'recording_notice', 'fallback_number',
        'override_on', 'override',
    ];

    protected function casts(): array
    {
        return [
            'record' => 'boolean',
            'transcribe' => 'boolean',
            'sms_outbound_allowed' => 'boolean',
            'lead_line' => 'boolean',
            'emergency_address_set' => 'boolean',
            'voice_caller_id_lookup' => 'boolean',
            'voicemail_transcribe' => 'boolean',

            // Twilio owns this; we keep the last answer so the list can draw
            // without asking. See the migration for why.
            'capabilities' => 'array',

            // What the line does instead of the call flow, when override_on.
            'override_on' => 'boolean',
            'override' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    /**
     * A setting, with the company's default behind it.
     *
     * ── Why this exists ──────────────────────────────────────────
     *
     * General looked like a company screen and behaved like a per-number
     * editor: what answers a call had to be set once per line, so seven
     * numbers meant seven settings and the eighth — bought next month —
     * silently answered differently from the rest.
     *
     * So the company decides, on its connection, and a line that has nothing
     * of its own INHERITS rather than meaning "nothing". `ring_group_id` is
     * the case that proves this is not hedging: a group claims one line and
     * not another through Ring groups, so that column genuinely varies per
     * line — and it still falls back, so a line no group claimed is answered
     * by the company's default group instead of by nobody.
     *
     * ── Read this, never the raw column ──────────────────────────
     *
     * Every caller in TwimlRenderer goes through here. A site left reading
     * `$number->flow_id` directly is a line still answering from its own
     * cleared column, which is to say answering with nothing, silently — the
     * exact failure this module keeps producing. `resolved()` is greppable;
     * the bare attribute is not.
     */
    /** An IVR menu plays, and the caller chooses. */
    public const ANSWER_MENU = 'menu';

    /** A team is dialled. */
    public const ANSWER_RING_GROUP = 'ring_group';

    /** Straight to voicemail, ON PURPOSE. */
    public const ANSWER_VOICEMAIL = 'voicemail';

    /** One named person's phones. */
    public const ANSWER_USER = 'user';

    /** Whoever this line belongs to — see [assignedUserId]. */
    public const ANSWER_SELF = 'self';

    /**
     * What a line may be set to answer with.
     *
     * NULL is not in the list and is not a sixth option: it means "as before"
     * — a menu if one is attached, otherwise the ring group, otherwise
     * voicemail — which is how all thirteen production lines behave and will
     * keep behaving until somebody chooses something here. See the migration.
     *
     * @return list<string>
     */
    public static function answerChoices(): array
    {
        return [
            self::ANSWER_MENU,
            self::ANSWER_RING_GROUP,
            self::ANSWER_VOICEMAIL,
            self::ANSWER_USER,
            self::ANSWER_SELF,
        ];
    }

    /**
     * The person this line belongs to, for `self`.
     *
     * An extension pointing at this number is what makes a line somebody's —
     * it is already how the Dial pad decides which line is yours, so `self`
     * needs no column of its own and cannot drift from what the pad shows.
     *
     * Null when nobody holds one, which is a real state and not an error: a
     * line set to ring "whoever it belongs to" and belonging to nobody reaches
     * voicemail, and the call-flow chart says so.
     */
    public function assignedUserId(): ?string
    {
        $id = PhoneExtension::query()
            ->where('number_id', $this->id)
            ->orderBy('created_at')
            ->value('user_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Where [resolved] took its value: 'line' for the line's own, 'company'
     * for the phone account's default, null when neither is set. The Call
     * flow screen says "follows the company" from this, not from a guess.
     */
    public function resolvedFrom(string $key): ?string
    {
        $own = $this->getAttribute($key);
        if ($own !== null && $own !== '') {
            return 'line';
        }

        $default = $this->getRelationValue('connection')?->getAttribute('default_'.$key);

        return $default !== null && $default !== '' ? 'company' : null;
    }

    public function resolved(string $key): mixed
    {
        $own = $this->getAttribute($key);

        // '' is a cleared text field, not a choice. Without this an emptied
        // fallback number would beat the company's rather than fall through.
        if ($own !== null && $own !== '') {
            return $own;
        }

        // getRelationValue, NOT $this->connection.
        //
        // Eloquent's Model has its own protected $connection holding the
        // DATABASE connection name, and from inside the class that property
        // wins over the relation — so `$this->connection` is the string
        // "mysql" here, not a PhoneConnection. From outside the class the
        // relation resolves normally, which is why every other call site is
        // fine and only this one had to learn it.
        return $this->getRelationValue('connection')
            ?->getAttribute('default_'.$key);
    }

    /**
     * A boolean setting, where false is a real answer and not an absence.
     *
     * Separate from [resolved] because `false ?? default` is not the question:
     * a line that says "do not record" means it, and inheriting `true` over it
     * would record a call somebody switched recording off for. Only a line
     * that has never been set defers — which, after the wipe, is all of them.
     */
    public function resolvedFlag(string $key): bool
    {
        return (bool) ($this->getAttribute($key)
            ?: $this->getRelationValue('connection')
                ?->getAttribute('default_'.$key));
    }

    public function grants(): HasMany
    {
        return $this->hasMany(PhoneNumberGrant::class, 'number_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(PhoneCall::class, 'number_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PhoneMessage::class, 'number_id');
    }
}
