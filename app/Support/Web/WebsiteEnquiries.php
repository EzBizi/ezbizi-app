<?php

namespace App\Support\Web;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Support\Leads\LeadThread;
use Illuminate\Support\Str;

/**
 * Enquiries that arrive from a customer's own website, turned into Leads.
 *
 * ── Why this writes Leads rather than a table of its own ──
 *
 * A person who fills in a form on a chimney company's website and a person who
 * rings the number in a Google ad are the same person to the business: somebody
 * who wants the work done. Giving website enquiries their own list would mean
 * two inboxes, two unread counts and two places to forget one. So a chat is a
 * Lead with `source` website_chat and a form submission is a Lead with
 * `website_form`, and everything Leads already does — the preview line, the
 * unreplied dot, `lead.contact_received`, booking into Scheduling — works on
 * them the day they arrive.
 *
 * ── Where the boundary is ──
 *
 * Web Management knows which SITE an enquiry came from and therefore which
 * company to place it under. Leads knows how to hold a message.
 * That is the whole division: this class resolves placement and hands the
 * message to [LeadThread], and never writes `lead_conversations` or the
 * `last_contact_*` cache itself.
 */
final class WebsiteEnquiries
{
    public function __construct(private readonly LeadThread $thread) {}

    /** Live chat from the widget on a customer's site. */
    public const CHANNEL_LIVECHAT = 'LIVECHAT';

    /** One submitted form, held as a single message on the thread. */
    public const CHANNEL_FORM = 'FORM';

    /**
     * Start a chat: a lead, a conversation with the visitor's first message,
     * and the token their browser will present to read the thread back.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, page_url?: ?string}  $visitor
     * @return array{0: WebChat, 1: string} the chat and its PLAINTEXT token, which is never stored
     */
    public function startChat(WebSite $site, string $message, array $visitor = []): array
    {
        [$plain, $hash] = WebChat::mintToken();

        $lead = $this->lead($site, Lead::SOURCE_WEBSITE_CHAT, 'chat_'.Str::uuid()->toString(), $visitor);

        $chat = new WebChat([
            'site_id' => $site->getKey(),
            'lead_id' => $lead->getKey(),
            'visitor_name' => $visitor['name'] ?? null,
            'page_url' => $visitor['page_url'] ?? null,
        ]);

        // Not fillable, and set before the insert rather than after it: the
        // column is NOT NULL, so a two-step save fails on the way in.
        $chat->forceFill(['token_hash' => $hash, 'last_seen_at' => now()])->save();

        $this->say($chat, $message, LeadConversation::FROM_CONSUMER);

        return [$chat, $plain];
    }

    /**
     * One more message on an existing chat, from either end.
     *
     * The remote id is the chat plus a random suffix rather than a counter: two
     * messages sent in the same second must not collide, and a counter would
     * need a lock to be safe under two browser tabs.
     */
    public function say(WebChat $chat, string $message, string $from): LeadConversation
    {
        $lead = $chat->lead;

        $this->thread->store($lead, $chat->getKey().':'.Str::random(16), [
            'channel' => self::CHANNEL_LIVECHAT,
            'participant' => $from,
            'occurred_at' => now(),
            'text' => $message,
        ]);

        $this->thread->refresh($lead);

        return LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderByDesc('occurred_at')
            ->firstOrFail();
    }

    /**
     * A submitted form, as one lead carrying one message.
     *
     * The fields are rendered into the message body rather than stored as their
     * own columns: a form is whatever the site owner made it, and a schema that
     * tried to hold every possible field would be a schema per customer.
     *
     * @param  array<string, string>  $fields
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, page_url?: ?string}  $visitor
     */
    public function submitForm(WebSite $site, array $fields, array $visitor = []): Lead
    {
        $remoteId = 'form_'.Str::uuid()->toString();
        $lead = $this->lead($site, Lead::SOURCE_WEBSITE_FORM, $remoteId, $visitor);

        $lines = [];

        foreach ($fields as $label => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        $this->thread->store($lead, $remoteId, [
            'channel' => self::CHANNEL_FORM,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(),
            'text' => implode("\n", $lines),
        ]);

        $this->thread->refresh($lead);

        return $lead;
    }

    /**
     * The lead an enquiry belongs to, placed by the site it came from.
     *
     * A site is registered to a company, and the company carries the franchise,
     * so placement needs no guess — which is the advantage a website enquiry has
     * over an inbound call.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, page_url?: ?string}  $visitor
     */
    private function lead(WebSite $site, string $source, string $remoteId, array $visitor): Lead
    {
        $phone = isset($visitor['phone']) ? trim((string) $visitor['phone']) : null;

        return Lead::create([
            'company_id' => $site->company_id,

            /*
             * No franchise. A company HAS franchises beneath it rather than
             * belonging to one, so a site registered to a company cannot name a
             * franchise without guessing which — and a lead placed under the
             * wrong branch is worse than one placed under none, because the
             * branch it belongs to never sees it.
             */
            'franchise_id' => null,
            'source' => $source,
            'remote_id' => $remoteId,
            'contact_name' => $visitor['name'] ?? null,
            'phone' => $phone !== '' ? $phone : null,
            'phone_digits' => $phone !== null && $phone !== '' ? Lead::digits($phone) : null,
            'email' => $visitor['email'] ?? null,
            'summary' => $site->host,
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);
    }
}
