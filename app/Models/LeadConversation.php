<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call or one message on a lead, as Google recorded it.
 *
 * Read-only from this side, like the lead it hangs off: nothing here sends a
 * message or places a call. A reply to an enquirer goes out because a person
 * sent it, and the record of that reply arrives here from Google on the next
 * sync the same way the enquirer's own words do.
 */
class LeadConversation extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const CHANNEL_PHONE_CALL = 'PHONE_CALL';
    public const CHANNEL_MESSAGE    = 'MESSAGE';

    public const FROM_CONSUMER   = 'CONSUMER';
    public const FROM_ADVERTISER = 'ADVERTISER';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A call or message exchanged with somebody who enquired through the organization\'s '
            .'advertising: when, which side, what was said, and for a call its length and a link '
            .'to Google\'s recording of it. Held with the enquiry it belongs to and deleted with '
            .'it; shown only to that organization.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'What an enquirer and the business said to each other, so the business can pick '
            .'up a conversation where it left off and can hear a call it was charged for.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('text', PersonalDataEntry::CONTENT,
                'A message in the sender\'s own words. Free text, so it can contain anything '
                .'they chose to write — an address, a name, a reason for the work.',
                Erasure::erase()),
            new PersonalDataEntry('recording_url', PersonalDataEntry::CONTENT,
                'Where Google keeps the recording of a call with this person — their voice. '
                .'EzBizi never copies the audio; it streams it on request with the connected '
                .'account\'s credentials, so removing the link removes the reach.',
                Erasure::erase()),
            new PersonalDataEntry('attachments', PersonalDataEntry::CONTENT,
                'Links to photographs or files the person sent with a message.',
                Erasure::erase()),
        ];
    }

    /** Same answer as Lead: nothing joins an enquirer to a person on this platform. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected $fillable = [
        'organization_id', 'lead_id', 'remote_id', 'channel', 'participant',
        'occurred_at', 'duration_ms', 'recording_url', 'text', 'attachments',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'duration_ms' => 'integer',
            'attachments' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
