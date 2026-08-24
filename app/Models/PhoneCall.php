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
            new PersonalDataEntry('recording_sid, transcription_sid', PersonalDataEntry::CONTENT,
                'Pointers at recorded audio and any transcript Twilio produced. The media '
                .'itself is personal content about whoever spoke.',
                Erasure::drop('Goes with the call row. The purge also asks Twilio to delete '
                    .'the recording so the SID cannot be replayed later.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if ($subject instanceof User) {
            return static::query()->where('user_id', $subject->getKey());
        }

        return null;
    }

    protected $fillable = [
        'organization_id', 'number_id', 'connection_id',
        'twilio_sid', 'parent_sid', 'direction',
        'from_e164', 'to_e164', 'user_id', 'status',
        'duration_seconds', 'recording_sid', 'transcription_sid',
        'voicemail', 'dealt_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'voicemail' => 'boolean',
            'dealt_at' => 'datetime',
        ];
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
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
