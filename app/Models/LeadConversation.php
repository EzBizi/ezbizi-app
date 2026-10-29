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

    public const CHANNEL_MESSAGE = 'MESSAGE';

    public const FROM_CONSUMER = 'CONSUMER';

    public const FROM_ADVERTISER = 'ADVERTISER';

    /**
     * `is_automatic` — software wrote this row, not a person.
     *
     * Set it on anything sent without somebody pressing Send: a website's
     * greeting, a rule's reply. It does not change what the thread SHOWS —
     * the words are the words and they are the newest thing said — it changes
     * whether the enquiry still counts as awaiting an answer.
     *
     * See LeadThread::refresh(). The short version: a robot saying hello is
     * not somebody answering, and "unreplied" is the list a business uses to
     * find the people nobody has got back to.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A call or message exchanged with somebody who enquired through the organization\'s '
            .'advertising: when, which side, what was said, and for a call its length and a link '
            .'to its recording. Held with the enquiry it belongs to and deleted with it; shown only to '
            .'that organization.'
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
            new PersonalDataEntry('transcript', PersonalDataEntry::CONTENT,
                'The words of a recorded call, transcribed on EzBizi\'s own server from the '
                .'recording Google keeps. The audio is fetched once, transcribed and deleted; '
                .'the words are kept here, with the enquiry.',
                Erasure::erase()),
            new PersonalDataEntry('transcript_labelled', PersonalDataEntry::CONTENT,
                'The same transcript divided by who was speaking (menu, dispatcher, '
                .'customer), done by Grok so it reads more easily.',
                Erasure::erase()),
            new PersonalDataEntry('call_summary', PersonalDataEntry::CONTENT,
                'One sentence on what the call was about, written by Grok from the transcript.',
                Erasure::erase()),
            new PersonalDataEntry('call_next_step', PersonalDataEntry::CONTENT,
                'What the business should do next after the call, written by Grok.',
                Erasure::erase()),
            new PersonalDataEntry('call_signals', PersonalDataEntry::CONTENT,
                'Four yes/no readings of the call by AI: a complaint, asked the price, asked '
                .'for a day, booked.',
                Erasure::erase()),
            new PersonalDataEntry('recording_url', PersonalDataEntry::CONTENT,
                'Where the recording of a call with this person — their voice — is kept: '
                .'Google, RingCentral or EzBizi\'s own phone line. EzBizi never copies the '
                .'audio; it streams it on request with the connected account\'s credentials, so '
                .'removing the link removes the reach.',
                Erasure::erase()),
            new PersonalDataEntry('recording_path', PersonalDataEntry::CONTENT,
                'Always empty. It named a saved copy of a call\'s recording; EzBizi saves no '
                .'call recordings (Jose, 2026-10-27) and the few copies made were deleted.',
                Erasure::erase()),
            new PersonalDataEntry('answered_by, rang', PersonalDataEntry::IDENTITY,
                'The staff member who picked up a call, or whose phones rang, as RingCentral '
                .'names them, so the business sees who handled it.',
                Erasure::keep('a work record of who handled a business call; it goes with the row')),
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

    /**
     * Fractions written, not only read. Eloquent formats dates for the
     * database with this, and the default drops the microseconds that
     * `occurred_at` holds since 2026_09_09_002500; two chat messages inside
     * one second then tie, and a tie's order is the engine's whim.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'organization_id', 'lead_id', 'remote_id', 'channel', 'participant',
        'occurred_at', 'duration_ms', 'line_number', 'line_name', 'answered_by', 'rang', 'recording_url', 'text', 'transcript', 'attachments',
        'is_automatic', 'is_ad_question',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'call_signals' => 'array',
            'duration_ms' => 'integer',
            'attachments' => 'array',
            'is_automatic' => 'boolean',
            'is_ad_question' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
