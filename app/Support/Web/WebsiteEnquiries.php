<?php

namespace App\Support\Web;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Automations\EventEmitter;
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
    public function __construct(
        private readonly LeadThread $thread,
        private readonly EventEmitter $events,
    ) {}

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
        $this->autoReply($chat, $site);

        /*
         * The SITE is the subject, not the lead. Leads raises
         * `lead.contact_received` for every source it holds; what a rule cannot
         * get from that is which website the person was standing on, which is
         * the whole reason a company with branch sites would write one.
         */
        $this->events->emitAfterCommit('website.chat_started', $site, [
            'after' => [
                'host' => $site->host,
                'page_url' => $visitor['page_url'] ?? null,
                'contact_name' => $visitor['name'] ?? null,
                'preview' => mb_substr($message, 0, 255),
            ],
        ]);

        return [$chat, $plain];
    }

    /**
     * One more message on an existing chat, from either end.
     *
     * The remote id is the chat plus a random suffix rather than a counter: two
     * messages sent in the same second must not collide, and a counter would
     * need a lock to be safe under two browser tabs.
     */
    /**
     * The answer that arrives before a person does.
     *
     * ── Why it is a real message ──
     *
     * The widget could draw a greeting locally and it would look identical to
     * the visitor. It would also be invisible in Leads, so whoever picks the
     * conversation up would not know what the person had already been told, and
     * would either repeat it or contradict it. Writing it to the thread costs
     * one row and makes the two views agree.
     *
     * ── Why only at the start ──
     *
     * Jose asked for it on the first reply. Every message would make a machine
     * that argues with people: somebody typing three lines in a row would get
     * three identical answers, each one more clearly nobody.
     *
     * ── Why blank means silence ──
     *
     * The switches came off the widgets on purpose, and this is not one coming
     * back: an empty greeting is not a disabled feature, it is a business that
     * would rather say nothing than say something automatic.
     *
     * ── What used to be wrong with it, and is not any more ──
     *
     * This wrote an ADVERTISER row, and "unreplied" in Leads read the newest
     * row's participant — so a greeting took a brand new enquiry out of the
     * list a business uses to find the people nobody has answered. Leads fixed
     * it at 072cb313: `is_automatic` is set here, the preview still shows the
     * greeting because it IS the most recent thing said, and `last_contact_from`
     * now comes from the newest row a PERSON was in.
     */
    private function autoReply(WebChat $chat, WebSite $site): void
    {
        $config = WebWidget::resolvedFor($site);
        $text = trim((string) ($config['chat']['auto_reply'] ?? ''));

        if ($text === '') {
            return;
        }

        /*
         * Both rows land in the same second, so they tie on occurred_at. The
         * id breaks it — LeadConversation ids are UUID v7 — and this one is
         * written second, so it reads second. See messagesFor().
         */
        $this->say($chat, $text, LeadConversation::FROM_ADVERTISER, automatic: true);
    }

    /**
     * @param  bool  $automatic  a machine said it, not a person
     *
     * Optional and defaulting to false because both callers are in this file
     * and only one of them is a machine. The flag is what keeps "unreplied" in
     * Leads honest: without it the greeting counts as somebody having answered,
     * and a brand new enquiry drops out of the list a business uses to find the
     * people nobody has replied to.
     */
    public function say(
        WebChat $chat,
        string $message,
        string $from,
        bool $automatic = false,
    ): LeadConversation {
        $lead = $chat->lead;

        $this->thread->store($lead, $chat->getKey().':'.Str::random(16), [
            'channel' => self::CHANNEL_LIVECHAT,
            'participant' => $from,
            'is_automatic' => $automatic,
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
     * Russian-language form spam, the kind that filled A-1's forms on
     * 2026-09-30 (seven in three hours, all ".ru" addresses or Cyrillic text).
     * A signal for a rule to act on, never a filter here: the lead still lands.
     *
     * ponytail: two tells only; a real captcha on the form is the fix (Web).
     *
     * @param  array<string, mixed>  $visitor
     * @param  array<string, string>  $fields
     */
    public static function looksLikeSpam(array $visitor, array $fields): bool
    {
        $email = strtolower(trim((string) ($visitor['email'] ?? '')));

        if (preg_match('/\.(ru|su)$/', $email) === 1) {
            return true;
        }

        $text = implode(' ', [...array_map('strval', $visitor), ...array_keys($fields), ...array_values($fields)]);

        return preg_match('/\p{Cyrillic}/u', $text) === 1;
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
    public function submitForm(WebSite $site, array $fields, array $visitor = [], array $attachments = []): Lead
    {
        $remoteId = 'form_'.Str::uuid()->toString();
        $lead = $this->lead($site, Lead::SOURCE_WEBSITE_FORM, $remoteId, $visitor, self::looksLikeSpam($visitor, $fields));

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
            'attachments' => $attachments === [] ? null : $attachments,
        ]);

        $this->thread->refresh($lead);

        $this->events->emitAfterCommit('website.form_submitted', $site, [
            'after' => [
                'host' => $site->host,
                'page_url' => $visitor['page_url'] ?? null,
                'contact_name' => $visitor['name'] ?? null,
                'preview' => mb_substr(implode(' · ', $lines), 0, 255),
            ],
        ]);

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
    private function lead(WebSite $site, string $source, string $remoteId, array $visitor, bool $looksLikeSpam = false): Lead
    {
        $phone = Lead::e164($visitor['phone'] ?? null);

        /*
         * CONSENT IS RECORDED ONLY WHEN IT IS FULLY EVIDENCED.
         *
         * The tick AND the words beside it. A tick with no wording is not a
         * record of what somebody agreed to, so it is treated as not given —
         * and the enquiry still lands either way. Losing a lead because its
         * consent was malformed would punish the visitor for the widget's bug.
         *
         * An older widget sends neither field, which is the same answer: no.
         */
        $consentText = trim((string) ($visitor['marketing_consent_text'] ?? ''));
        $consented = ($visitor['marketing_consent'] ?? false) === true && $consentText !== '';

        $lead = Lead::create([
            'company_id' => $site->company_id,

            /*
             * The branch this website answers for, when it answers for one.
             *
             * A company HAS franchises rather than belonging to one, so this
             * cannot be derived — the site has to be pointed at a branch, and
             * most never are. Null places the enquiry at company level, which
             * is right for a single-site business and honest for everyone else:
             * a lead under the wrong branch is worse than one under none,
             * because the branch it belongs to never sees it.
             */
            'franchise_id' => $site->franchise_id,
            'source' => $source,
            'remote_id' => $remoteId,
            'contact_name' => $visitor['name'] ?? null,
            'phone' => $phone !== '' ? $phone : null,
            'phone_digits' => $phone !== null && $phone !== '' ? Lead::digits($phone) : null,
            'email' => $visitor['email'] ?? null,
            'marketing_consent_at' => $consented ? now() : null,
            'marketing_consent_text' => $consented ? mb_substr($consentText, 0, 500) : null,
            'summary' => $site->host,
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);

        /*
         * The lead's ARRIVAL, the same trigger Google and Thumbtack raise on
         * create. Without it an automation on "Lead received" fired for two
         * sources and silently skipped every website chat and form - the
         * rule enabled, no error, three sources out of four (UI/UX,
         * 2026-09-08). lead.contact_received for the words themselves comes
         * from LeadThread; this is the header.
         */
        $this->events->emitAfterCommit('lead.received', $lead, [
            'after' => $lead->only([
                'source', 'contact_name', 'phone', 'category', 'received_at',
            ]) + [
                'kind' => $lead->kind(),
                // A yes/no a rule can test, so "only if they agreed to email"
                // can be written as a condition as well as enforced by the step.
                'marketing_consent' => $consented,
                // For a rule that files it as Not a lead (looksLikeSpam).
                'looks_like_spam' => $looksLikeSpam,
            ],
        ]);

        return $lead;
    }
}
