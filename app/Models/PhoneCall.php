<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Twilio call or voicemail leg we keep a record of (spec §11).
 */
class PhoneCall extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_RINGING = 'ringing';
    public const STATUS_IN_PROGRESS = 'in-progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_ANSWER = 'no-answer';
    public const STATUS_BUSY = 'busy';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    /**
     * The company blocked this caller, so no phone ever rang.
     *
     * Distinct from every miss above it. `no-answer` says nobody picked up
     * and somebody might want to call back; this says the call was refused on
     * purpose, and collapsing the two would put a robocaller in the list of
     * people owed a callback.
     */
    public const STATUS_BLOCKED = 'blocked';

    public static function personalDataPurpose(): string
    {
        return 'Call and voicemail history: who dialled whom, how long it lasted, and any '
            .'recording or transcript Twilio still holds a SID for.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Default matches business audit until a Phone-specific setting exists (spec §11). '
            .'Older call rows and their Twilio recording SIDs stop being useful long before '
            .'they stop naming outside numbers.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('from_e164, to_e164', PersonalDataEntry::CONTACT,
                'The numbers on the call. Usually a customer or other outside person, not '
                .'only the business line.',
                Erasure::drop('A call row is nothing but that exchange. Blanking the numbers '
                    .'leaves a SID with no parties; the row goes, and Twilio media is deleted '
                    .'by the purge path that reads these declarations.')),
            new PersonalDataEntry('user_id, answered_by', PersonalDataEntry::IDENTITY,
                'Which employee placed the call, and which of the people it rang picked '
                .'it up, when that is known.',
                Erasure::keep('A foreign key to the account the purge anonymises at its home. '
                    .'Nulling it here would orphan a dated call from the person who took it '
                    .'without removing anything about them.')),
            new PersonalDataEntry('recording_sid, transcription_sid, transcript, voicemail_transcript', PersonalDataEntry::CONTENT,
                'Pointers at recorded audio, any transcript SID Twilio produced, the '
                .'concatenated final transcript text we keep on the row, and separately the '
                .'text of any message left on voicemail. The media itself is '
                .'personal content about whoever spoke.',
                Erasure::drop('Goes with the call row. The purge also asks Twilio to delete '
                    .'the recording so the SID cannot be replayed later.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if ($subject instanceof User) {
            // Calls they PLACED, and deliberately not ones they answered.
            // These rows are erased by dropping them, and an inbound call is
            // as much the customer's record as the employee's — losing it
            // because the person who picked up has left would erase somebody
            // who did not ask to be. `answered_by` is declared instead as a
            // key to an account anonymised at its own home.
            return static::query()->where('user_id', $subject->getKey());
        }

        if ($subject instanceof Customer) {
            // Exact match on customers.phone ↔ from_e164/to_e164. Read the phone
            // from the subject instance: Customer is blanked earlier in the
            // alphabetical purge walk, and reloading the row would miss every
            // call that still names the original number.
            $phone = (string) ($subject->getAttribute('phone') ?? '');

            if ($phone === '') {
                return static::query()->whereRaw('0 = 1');
            }

            return static::query()->where(fn (Builder $q) => $q
                ->where('from_e164', $phone)
                ->orWhere('to_e164', $phone));
        }

        return null;
    }

    protected $fillable = [
        'organization_id', 'number_id', 'connection_id',
        'twilio_sid', 'parent_sid', 'direction',
        'from_e164', 'to_e164', 'user_id', 'answered_by', 'status',
        'parked_at', 'parked_by',
        'error_code', 'error_message',
        'attestation',
        'duration_seconds', 'recording_sid', 'transcription_sid', 'transcript',
        'voicemail_transcript',
        'voicemail', 'dealt_at',
        'tag_id', 'tagged_by', 'tagged_at',
    ];

    /**
     * The Twilio queue this call waits in while it is parked.
     *
     * Named after the CALL, which is what makes park slotless: there is
     * nothing to allocate, nothing to run out of, and two people parking at
     * once cannot collide. A desk phone needs numbered park slots because it
     * has buttons and a button needs a number; a screen can list the calls
     * themselves, which is also the more useful thing to look at — you pick up
     * "the call from +1 305…", not "slot 3".
     *
     * Prefixed `ezbizi-park-` rather than `ezbizi-` so a park queue is never
     * mistaken for a ring group's hold queue, which is the same account and a
     * different feature.
     */
    public function parkQueueName(): string
    {
        return 'ezbizi-park-'.$this->id;
    }

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'voicemail' => 'boolean',
            'dealt_at' => 'datetime',
            'tagged_at' => 'datetime',
        ];
    }

    /**
     * Which company's line this happened on (§4, §5.5).
     *
     * Without this the dispatcher's column sniff looks for `franchise_id` and
     * `owning_franchise_id`, finds neither on this table, and reads that as "no
     * scope" — which its organization branch turns into EVERY ACTIVE USER. So a
     * rule saying "notify me" on `phone.call.ended` would have carried the customer's number and who spoke to them to
     * everybody in the business, including the companies that do not answer
     * that line. That is the exact leak NotificationDispatcher's own header says
     * reach exists to prevent, arriving through a record shape its sniff was
     * never written for.
     *
     * The scope is the NUMBER's, not the call's, because the call has no place of
     * its own: it belongs wherever the line it arrived on is filed. That is the
     * same answer PhoneScope gives when deciding who may open it — one rule read
     * twice, rather than a second derivation that can disagree with who is
     * allowed to see the record.
     *
     * A row whose number has been deleted answers `[null, null]` rather than
     * guessing, and that is the conservative direction rather than a gap: the
     * organization branch reaches the people who STAND at the organization, not
     * everyone in it.
     */
    public function automationScope(): array
    {
        $number = $this->number;

        return match ($number?->scope_type) {
            PhoneNumber::SCOPE_FRANCHISE => ['franchise', $number->scope_id],
            PhoneNumber::SCOPE_COMPANY => ['company', $number->scope_id],
            default => [null, null],
        };
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(PhoneCallTag::class, 'tag_id');
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
