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
 * A reply is a person's sentence to a stranger in the customer's name. Which
 * of these may be sent WITHOUT somebody pressing Send is
 * `allowsUnattendedReply()`, and that answer is not a matter of taste - see
 * the method.
 */
interface LeadReplyChannel
{
    /** The `Lead::source` value this channel answers for. */
    public function source(): string;

    /** Whether this particular lead can be replied to right now. */
    public function canReply(Lead $lead): bool;

    /**
     * May software send on this channel with nobody pressing Send?
     *
     * ── This is a compliance answer, not a preference ────────────
     *
     * Until 2026-09-16 this file said only "never from a rule", and Automations
     * read that as somebody's judgement about unattended replies - reasonably,
     * because the paragraph carried the rule and not the stakes. It carries
     * both now.
     *
     * For Local Services the reason is a filing. EzBizi told
     * `ads-api-compliance` on 2026-09-15, and Google's OAuth Trust and Safety
     * team on 2026-09-16, in these words:
     *
     *     "The only write is appendLeadConversation: one reply a member of
     *      staff types and sends to a text enquiry."
     *
     * Both submissions are open, and the OAuth one has already bounced twice on
     * discrepancies between what was filed and what the product does. A rule
     * that reaches a Google lead makes that sentence false while a reviewer is
     * reading it. So the answer there was no.
     *
     * Jose, 2026-09-29, deciding it: "flip the block now, there's no need to tell
     * google about it" - so an automation (the auto reply that replaces Rodrigo's
     * bot) may answer a Google text enquiry. Local
     * Services answers yes from then on; the filing above was not re-sent.
     *
     * Thumbtack answers no as well, and that one IS a choice: no filing forbids
     * it, and nobody has argued for it. A new channel should arrive false and
     * be argued up.
     *
     * Website chat answers yes. Nothing leaves EzBizi's own database, no third
     * party is told anything, and the product already sends an unattended
     * greeting there.
     *
     * Deliberately REQUIRED rather than defaulted. A default false would be
     * safer to forget, and forgetting is the failure: a channel that never
     * thought about this would silently inherit an answer nobody chose. Adding
     * it reds every implementor, which is the point.
     */
    public function allowsUnattendedReply(): bool;

    /**
     * Send, and return the row the thread shows as ours.
     *
     * ── `$automatic` is a PATH, not an instruction ───────────────
     *
     * This parameter exists because a docblock telling callers to mark the row
     * was not a mechanism. Between 2026-09-16 and the commit that added it,
     * two files said "callers must mark the row `is_automatic`" and the chain
     * from here to the row had nowhere to carry it: `send()` took a Lead and a
     * string, and there was no third thing to pass. Automations found it while
     * writing the line that would have passed it — everything READ the column
     * and nothing could WRITE it. A rule's reply would have gone in as a
     * person's and taken the enquiry off the Unreplied list, which is the exact
     * failure the column was added to prevent, arriving through the front door.
     *
     * Defaulted false so the human composer — the only caller today — is
     * untouched and no implementor changes signature to keep compiling.
     *
     * A channel answering false to `allowsUnattendedReply()` must REFUSE a true
     * flag rather than quietly accept it. Two guards on one boundary is not
     * belt and braces here: the first is asked by the caller and the second by
     * the callee, and only the second is in the room when a new caller appears.
     *
     * @param  bool  $automatic  software is sending this, nobody pressed Send
     *
     * @throws \RuntimeException with a sentence for the screen when the
     *                           provider refuses; never retried by the caller
     */
    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation;
}
