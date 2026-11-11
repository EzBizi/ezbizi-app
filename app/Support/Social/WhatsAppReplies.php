<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNumber;
use App\Support\Leads\ExplainsRefusal;
use App\Support\Leads\HasReplyWindow;
use App\Support\Leads\LeadReplyChannel;
use App\Support\Leads\LeadThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Answering a WhatsApp lead from Leads, on the number they wrote to.
 *
 * WhatsApp allows a free-form message only within 24 hours of the person's
 * last message (the customer-service window), the same rule as Messenger -
 * see MetaMessageReplies.
 */
final class WhatsAppReplies implements ExplainsRefusal, HasReplyWindow, LeadReplyChannel
{
    private const WINDOW_HOURS = 24;

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly LeadThread $thread,
    ) {}

    public function source(): string
    {
        return Lead::SOURCE_WHATSAPP;
    }

    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    public function canReply(Lead $lead): bool
    {
        return $this->number($lead) !== null && $this->lastInboundWithinWindow($lead);
    }

    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $number = $this->number($lead);

        if ($number === null) {
            throw new RuntimeException(
                'The WhatsApp number this enquiry came to is no longer set up, so there is '
                .'nowhere to send the reply from.'
            );
        }

        if (! $this->lastInboundWithinWindow($lead)) {
            throw new RuntimeException(
                'WhatsApp only allows a reply within '.self::WINDOW_HOURS.' hours of the last '
                .'message from this person, and that has passed.'
            );
        }

        $sent = $this->cloud->replyFreeForm($number, (string) $lead->remote_id, [
            'type' => 'text',
            'text' => ['body' => $text],
        ], $text);

        $this->thread->store($lead, (string) ($sent->wamid ?? 'sent_'.$lead->getKey().'_'.now()->getTimestampMs()), [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $text,
            'is_automatic' => $automatic,
        ]);

        $this->thread->refresh($lead);

        return LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderByDesc('occurred_at')
            ->firstOrFail();
    }

    /** Why not, and what can still be sent: an approved template opens a closed window. */
    public function whyNot(Lead $lead): string
    {
        if ($this->number($lead) === null) {
            return 'The WhatsApp number this enquiry came to is no longer set up, so there is nowhere to send a reply from.';
        }

        $ends = $this->replyWindowEndsAt($lead);

        if ($ends === null) {
            return 'There is no message from this person to reply to yet. You can send them an approved template.';
        }

        return 'WhatsApp only allows free replies within '.self::WINDOW_HOURS." hours of the person's last message. "
            .'Their last message was '.$ends->copy()->subHours(self::WINDOW_HOURS)->diffForHumans().'; you can send an approved template now, or reply freely if they write back.';
    }

    public function replyWindowEndsAt(Lead $lead): ?Carbon
    {
        $last = LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_CONSUMER)
            ->max('occurred_at');

        return $last === null ? null : Carbon::parse($last)->addHours(self::WINDOW_HOURS);
    }

    /**
     * The approved templates of the number this lead wrote to - the one thing
     * WhatsApp accepts once the window has closed - through the same
     * five-minute cache as the automation builder's list.
     *
     * @return array<int, array{name: string, language: string, category: string, parameters: int, preview: string}>
     */
    public function templates(Lead $lead): array
    {
        $waba = (string) ($this->number($lead)?->waba_id ?? '');

        return $waba === '' ? [] : Cache::remember('whatsapp.templates.'.$waba, 300, fn () => $this->cloud->templates($waba));
    }

    /** The number to send from, as WhatsAppCloud::send() names it. */
    public function fromNumber(Lead $lead): ?string
    {
        return $this->number($lead)?->display_number;
    }

    /** The number the person wrote to, through the contact that made this lead. */
    private function number(Lead $lead): ?WhatsAppNumber
    {
        $phoneNumberId = WhatsAppContact::query()->where('lead_id', $lead->getKey())->value('phone_number_id');

        return $phoneNumberId === null
            ? null
            : WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->first();
    }

    private function lastInboundWithinWindow(Lead $lead): bool
    {
        $ends = $this->replyWindowEndsAt($lead);

        return $ends !== null && now()->lt($ends);
    }
}
