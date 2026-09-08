<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;

/**
 * How a reply reaches the enquirer, for one source of leads.
 *
 * The thread's composer is the same for every source; where the text goes is
 * not. Google's Local Services take it through appendLeadConversation; a
 * website chat takes it as a row the visitor's next poll picks up; a website
 * form has nowhere to send it. Each source implements this once and tags it
 * `lead-reply-channels` in its own provider - see [LeadReplies] - so the
 * controller never learns a source by name.
 *
 * A reply is a person's sentence to a stranger in the customer's name. Every
 * implementation sends only because somebody pressed Send, never from a rule.
 */
interface LeadReplyChannel
{
    /** The `Lead::source` value this channel answers for. */
    public function source(): string;

    /** Whether this particular lead can be replied to right now. */
    public function canReply(Lead $lead): bool;

    /**
     * Send, and return the row the thread shows as ours.
     *
     * @throws \RuntimeException with a sentence for the screen when the
     *                           provider refuses; never retried by the caller
     */
    public function send(Lead $lead, string $text): LeadConversation;
}
