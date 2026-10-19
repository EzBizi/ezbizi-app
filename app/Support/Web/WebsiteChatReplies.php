<?php

namespace App\Support\Web;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Support\Leads\LeadReplyChannel;
use RuntimeException;

/**
 * Replying to somebody who is standing on the customer's website.
 *
 * ── Why this is the easiest channel in the application ──
 *
 * There is no provider to call and nothing to deliver. The visitor's widget is
 * already asking every few seconds whether anything has been said, so a reply
 * is a row written to the thread — the same row the screen shows — and the
 * next poll picks it up. Nothing here can half-succeed the way an SMS or an
 * email can, which is why it throws on exactly one condition and no others.
 *
 * ── What "can reply" means, and what it deliberately does not ──
 *
 * It means the chat row still exists. It does NOT mean the visitor is still
 * there: nobody can know that, the widget stops polling the moment the tab
 * closes, and a screen that greyed out the composer on a guess would stop
 * people answering questions asked ten minutes ago. An unread reply is
 * waiting, not lost — the visitor sees it whenever they come back inside the
 * session, and the enquiry keeps their details either way.
 *
 * Forms have no channel and no implementation here, on purpose. A submitted
 * form has no page left to answer into, and quietly falling through to email
 * would be a second send path with its own consent and audit questions,
 * wearing this one's name.
 */
final class WebsiteChatReplies implements LeadReplyChannel
{
    public function __construct(private readonly WebsiteEnquiries $enquiries) {}

    public function source(): string
    {
        return Lead::SOURCE_WEBSITE_CHAT;
    }

    /**
     * Yes. Nothing leaves EzBizi's own database and no third party is told
     * anything, which is why this is the one channel a rule may send on.
     *
     * The product already sends unattended here - `WebsiteEnquiries::autoReply`
     * greets a visitor before a person arrives. Anything sending without a
     * person must mark the row `is_automatic`, or it reads as somebody having
     * answered and takes the enquiry out of the Unreplied list.
     *
     * Added by Leads with the interface, 2026-09-16. This file is Web
     * Management's; the answer is theirs to change.
     */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    public function canReply(Lead $lead): bool
    {
        return $this->chatFor($lead) !== null;
    }

    /**
     * `$automatic` says a machine wrote this, not a person.
     *
     * It is carried through to the row, which is the whole point: without it a
     * rule-driven reply reads as somebody having answered, and the enquiry
     * drops off the unreplied list while nobody has actually replied.
     *
     * This parameter arrived from Leads (be14359e) signed for but not passed
     * on, because `say()` did not take it on main at the time — a deliberate
     * half, with a note naming this branch. Both halves are now here.
     */
    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $chat = $this->chatFor($lead);

        if ($chat === null) {
            /*
             * The only way to get here: the lead says website_chat but its
             * web_chats row is gone. The sentence names the consequence rather
             * than the cause, because the person reading it is mid-reply and
             * cannot do anything about a missing row.
             */
            throw new RuntimeException(
                'This conversation is no longer open on the website, so there is '
                .'nowhere to send that. The enquirer’s details are still here.'
            );
        }

        return $this->enquiries->say($chat, $text, LeadConversation::FROM_ADVERTISER, $automatic);
    }

    private function chatFor(Lead $lead): ?WebChat
    {
        // The newest: a person's second chat joins their first lead (2026-10-19).
        return WebChat::query()->where('lead_id', $lead->getKey())->latest()->first();
    }
}
