<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;

/**
 * A reply to a RingCentral call or a website form, which came with no chat
 * to answer into: a text to the lead's phone from the company's texting line,
 * kept on the lead's thread (LeadTexts). Jose, 2026-11-03: the not-booked
 * recovery text, sent and shown on the lead.
 */
final class SmsLeadReplies implements LeadReplyChannel
{
    public function __construct(
        private readonly string $source,
        private readonly LeadTexts $texts,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    /** As the `lead.text` step already does for any lead with a phone. */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    public function canReply(Lead $lead): bool
    {
        return Lead::e164($lead->phone) !== null && $this->texts->line($lead) !== null;
    }

    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        return $this->texts->send($lead, $text, request()->user(), $automatic);
    }
}
