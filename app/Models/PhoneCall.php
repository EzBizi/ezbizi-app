<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
class PhoneCall extends Model implements HoldsPersonalData
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
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee answered or placed the call, when that is known.',
                Erasure::keep('A foreign key to the account the purge anonymises at its home. '
                    .'Nulling it here would orphan a dated call from the person who took it '
                    .'without removing anything about them.')),
            new PersonalDataEntry('recording_sid, transcription_sid, transcript', PersonalDataEntry::CONTENT,
                'Pointers at recorded audio, any transcript SID Twilio produced, and the '
                .'concatenated final transcript text we keep on the row. The media itself is '
                .'personal content about whoever spoke.',
                Erasure::drop('Goes with the call row. The purge also asks Twilio to delete '
                    .'the recording so the SID cannot be replayed later.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if ($subject instanceof User) {
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
        'from_e164', 'to_e164', 'user_id', 'status',
        'attestation',
        'duration_seconds', 'recording_sid', 'transcription_sid', 'transcript',
        'voicemail', 'dealt_at',
        'tag_id', 'tagged_by', 'tagged_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'voicemail' => 'boolean',
            'dealt_at' => 'datetime',
            'tagged_at' => 'datetime',
        ];
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
