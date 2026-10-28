<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneCall;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\TextRefused;
use RuntimeException;

/**
 * A reply to an EZ Text or EZ Call lead, as a text from the EzBizi line the
 * customer last reached (Jose, 2026-10-16). Phone's PhoneTexts sends it, so
 * the line's own rules hold: a line that may not text refuses.
 */
final class EzTextReplies implements LeadReplyChannel
{
    public function __construct(
        private readonly string $source,
        private readonly PhoneTexts $texts,
        private readonly LeadThread $thread,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    /** A text from the business's own line, like a person sending it from Communication. */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    public function canReply(Lead $lead): bool
    {
        return $lead->phone !== null && ($this->line($lead)?->sms_outbound_allowed ?? false);
    }

    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $line = $this->line($lead);
        if ($line === null || $lead->phone === null) {
            throw new RuntimeException('The EzBizi line this customer reached is no longer set up, so there is nowhere to text from.');
        }

        try {
            $sent = $this->texts->send($line, (string) $lead->phone, $text);
        } catch (TextRefused $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $this->thread->store($lead, 'ez_sent_'.$sent->id, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $text,
            'is_automatic' => $automatic,
        ]);
        $this->thread->refresh($lead);

        return LeadConversation::query()->where('remote_id', 'ez_sent_'.$sent->id)->firstOrFail();
    }

    /**
     * The line of the latest EZ call or text on this lead, or of a text
     * LeadTexts sent or filed on it (`sms_<sid>`): a Re-Scheduling offer opens
     * an EZ Texts lead with nothing else on it (Jose, 2026-10-28).
     */
    private function line(Lead $lead): ?PhoneNumber
    {
        $last = LeadConversation::query()->where('lead_id', $lead->getKey())
            ->where(fn ($q) => $q->where('remote_id', 'like', EzLeadCalls::TEXT.'%')->orWhere('remote_id', 'like', EzLeadCalls::CALL.'%')
                ->orWhere('remote_id', 'like', 'sms\_%'))
            ->orderByDesc('occurred_at')->value('remote_id');
        if ($last === null) {
            return null;
        }

        $numberId = match (true) {
            str_starts_with($last, EzLeadCalls::TEXT) => PhoneMessage::query()->whereKey(substr($last, strlen(EzLeadCalls::TEXT)))->value('number_id'),
            str_starts_with($last, EzLeadCalls::CALL) => PhoneCall::query()->whereKey(substr($last, strlen(EzLeadCalls::CALL)))->value('number_id'),
            default => PhoneMessage::query()->where('twilio_sid', substr($last, 4))->value('number_id'),
        };

        return $numberId === null ? null : PhoneNumber::query()->find($numberId);
    }
}
