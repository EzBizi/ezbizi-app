<?php

namespace App\Support\Web;

use App\Jobs\DeliverAutomationEvent;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\ServiceArea;
use App\Models\WebBooking;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadThread;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

        // A chat joins only an earlier CHAT: its replies go back through the
        // chat, which a form's lead has no way to do.
        $lead = self::recentWebLead($site, $visitor, [Lead::SOURCE_WEBSITE_CHAT])
            ?? $this->lead($site, Lead::SOURCE_WEBSITE_CHAT, 'chat_'.Str::uuid()->toString(), $visitor);

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
         * The greeting rule ("Auto reply to new website chats") answers in
         * seconds, not at the next sweep. Automation events wait for a
         * once-a-minute outbox sweep; a person who just opened a chat watched
         * 35 seconds of nothing (Jose, 2026-10-01). This claims the lead's
         * event exactly as the sweep does, so the sweep cannot queue it twice.
         */
        DB::afterCommit(fn () => self::deliverNow($lead));

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

    /** Queue this lead's arrival event now, claimed as the sweep claims it. */
    private static function deliverNow(Lead $lead): void
    {
        $event = AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'lead.received')
            ->where('subject_id', $lead->getKey())
            ->whereNull('queued_at')
            ->first();

        if ($event !== null && AutomationEvent::withoutGlobalScopes()->whereKey($event->getKey())
            ->whereNull('queued_at')->update(['queued_at' => now()]) === 1) {
            DeliverAutomationEvent::dispatch($event->getKey());
        }
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
        array $attachments = [],
    ): LeadConversation {
        $lead = $chat->lead;

        $this->thread->store($lead, $chat->getKey().':'.Str::random(16), [
            'channel' => self::CHANNEL_LIVECHAT,
            'participant' => $from,
            'is_automatic' => $automatic,
            'occurred_at' => now(),
            'text' => $message,
            // A visitor's photo, by its signed link; Leads shows attachments as links.
            'attachments' => $attachments === [] ? null : $attachments,
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
     * The site's own robot check (Cloudflare Turnstile, reCAPTCHA, hCaptcha)
     * rides along as a form field; its token is noise in the thread (Jose,
     * 2026-10-09: "it should be hidden").
     */
    public static function isRobotCheck(string $label): bool
    {
        return preg_match('/turnstile|recaptcha|captcha/i', $label) === 1;
    }

    /**
     * A form condensed to one line per thing, values only, in the order a
     * dispatcher reads it (Bar via Jose, 2026-10-12): name, phone, email, when,
     * the address on one line, service, note. Empty fields are left out; a
     * field it cannot place keeps its label underneath, so nothing is lost.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $visitor  the form's own name, phone and email, when the fields lack them
     * @return list<string>
     */
    public static function condensed(array $fields, array $visitor = []): array
    {
        $f = array_fill_keys(['rating', 'first', 'last', 'name', 'phone', 'email', 'date', 'time',
            'line1', 'line2', 'city', 'state', 'zip', 'service', 'note'], '');
        $other = [];

        foreach ($fields as $label => $value) {
            $label = (string) $label;
            $value = trim((string) $value);
            if ($value === '' || self::isRobotCheck($label)) {
                continue;
            }
            $key = match (true) {
                (bool) preg_match('/rating/i', $label) => 'rating',
                (bool) preg_match('/e-?mail/i', $label) => 'email',
                (bool) preg_match('/phone|mobile|cell|\btel/i', $label) => 'phone',
                (bool) preg_match('/first.?name/i', $label) => 'first',
                (bool) preg_match('/last.?name|surname/i', $label) => 'last',
                (bool) preg_match('/^(full.?)?name$|your name/i', $label) => 'name',
                (bool) preg_match('/date|day|when/i', $label) => 'date',
                (bool) preg_match('/time|window/i', $label) => 'time',
                (bool) preg_match('/line.?2|suite|apt|unit/i', $label) => 'line2',
                (bool) preg_match('/city|town/i', $label) => 'city',
                (bool) preg_match('/^state|province/i', $label) => 'state',
                (bool) preg_match('/zip|postal/i', $label) => 'zip',
                (bool) preg_match('/address|street/i', $label) => 'line1',
                (bool) preg_match('/service|need|job|work/i', $label) => 'service',
                (bool) preg_match('/note|message|comment|detail|describe|happened/i', $label) => 'note',
                default => null,
            };
            if ($key === null || $f[$key] !== '') {
                $other[] = '▫️ '.$label.': '.$value;   // unplaced, or a second of the same
            } else {
                $f[$key] = $value;
            }
        }

        foreach (['name' => 'name', 'phone' => 'phone', 'email' => 'email'] as $key => $from) {
            if ($f[$key] === '' && ($key !== 'name' || $f['first'].$f['last'] === '')) {
                $f[$key] = trim((string) ($visitor[$from] ?? ''));
            }
        }

        $digits = preg_replace('/\D+/', '', $f['phone']) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        $phone = strlen($digits) === 10
            ? '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6)
            : $f['phone'];

        $join = fn (string $sep, string ...$parts) => implode($sep, array_filter($parts, fn ($p) => $p !== ''));
        $name = $join(' ', $f['first'], $f['last']);
        if ($name === '') {
            $name = $f['name'];
        } elseif ($f['name'] !== '') {
            $other[] = '▫️ Name: '.$f['name'];
        }

        $lines = [];
        foreach ([
            '⭐ ' => $f['rating'],
            '👤 ' => $name,
            '📞 ' => $phone,
            '✉️ ' => $f['email'],
            '📅 ' => $join(' ', $f['date'], $f['time']),
            '📍 ' => $join(', ', $join(' ', $f['line1'], $f['line2']), $f['city'], $join(' ', $f['state'], $f['zip'])),
            '🛠️ ' => $f['service'],
            '📝 ' => $f['note'],
        ] as $mark => $value) {
            if ($value !== '') {
                $lines[] = $mark.$value;
            }
        }

        return [...$lines, ...$other];
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

        // The review link's faces form sends a Rating: a rating, not an enquiry
        // (Jose, 2026-10-09). Only "Not happy" comes here today; happy goes to Google.
        // Set at creation, so lead.received carries it: a complaint rule (Jose,
        // 2026-10-13: a task, an alert and a text) triggers on rating = sad.
        if (isset($fields['Rating'])) {
            $visitor['lead_type'] = stripos((string) $fields['Rating'], 'not') === false ? 'GOOD_RATING' : 'BAD_RATING';
            $visitor['comment'] = $fields['What happened?'] ?? null;
        }

        // The same phone sending a form again within a day joins its earlier
        // form's lead (Jose, 2026-10-19: Elizabeth Bell sent the sad-face form
        // twice, 6 minutes apart, and showed as two leads - "yes, it should").
        $lead = self::recentWebLead($site, $visitor, [Lead::SOURCE_WEBSITE_FORM, Lead::SOURCE_WEBSITE_CHAT, Lead::SOURCE_WEBSITE_BOOKING])
            ?? $this->lead($site, Lead::SOURCE_WEBSITE_FORM, $remoteId, $visitor, self::looksLikeSpam($visitor, $fields));

        $lines = self::condensed($fields, $visitor);

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
     * An appointment asked for on the booking widget: a lead in Leads ▸ Online
     * Booking whose first message is the request, and the WebBooking row that
     * holds the window's seat until a dispatcher decides.
     *
     * @param  array{name?: ?string, phone?: ?string, zip?: ?string, page_url?: ?string}  $visitor
     */
    public function submitBooking(WebSite $site, ServiceArea $area, string $date, array $window, string $address, array $visitor, ?Lead $existing = null): WebBooking
    {
        $remoteId = 'booking_'.Str::uuid()->toString();
        // From the lead's own booking link (Jose, 2026-10-13): the request joins
        // that lead's thread rather than starting a second lead.
        $lead = $existing ?? self::recentWebLead($site, $visitor, [Lead::SOURCE_WEBSITE_FORM, Lead::SOURCE_WEBSITE_CHAT, Lead::SOURCE_WEBSITE_BOOKING])
            ?? $this->lead($site, Lead::SOURCE_WEBSITE_BOOKING, $remoteId, $visitor);
        $visitor['name'] = ($visitor['name'] ?? '') !== '' ? $visitor['name'] : $lead->contact_name;
        $visitor['phone'] = ($visitor['phone'] ?? '') !== '' ? $visitor['phone'] : $lead->phone;

        // The area knows the branch better than the site does.
        if ($lead->franchise_id === null && $area->franchise_id !== null) {
            $lead->forceFill(['franchise_id' => $area->franchise_id])->save();
        }

        $when = CarbonImmutable::parse($date);
        $fmt = fn (string $t) => CarbonImmutable::parse($date.' '.$t)->format('g:i A');

        $this->thread->store($lead, $remoteId, [
            'channel' => self::CHANNEL_FORM,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(),
            'text' => implode("\n", [
                'Requested: '.$when->format('l, F j').', '.$fmt($window['start_time']).' - '.$fmt($window['end_time']),
                'Name: '.($visitor['name'] ?? ''),
                'Phone: '.($visitor['phone'] ?? ''),
                'Address: '.$address,
                'ZIP: '.($visitor['zip'] ?? ''),
            ]),
        ]);

        $this->thread->refresh($lead);

        return WebBooking::create([
            'site_id' => $site->getKey(),
            'lead_id' => $lead->getKey(),
            'service_area_id' => $area->getKey(),
            'date' => $date,
            'start_time' => $window['start_time'],
            'end_time' => $window['end_time'],
            'status' => WebBooking::PENDING,
        ]);
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
    /**
     * The same person's website lead from the last 24 hours, in the site's own
     * company: by phone, or by email when they gave no phone (Jose, 2026-10-19:
     * a repeat form, Bad Rating, chat or booking "joins the earlier lead's
     * thread"). The message then lands there, and LeadThread reopens it.
     *
     * @param  list<string>  $sources
     */
    public static function recentWebLead(WebSite $site, array $visitor, array $sources): ?Lead
    {
        $digits = Lead::digits((string) Lead::e164($visitor['phone'] ?? null));
        $email = mb_strtolower(trim((string) ($visitor['email'] ?? '')));
        $q = Lead::query()->whereIn('source', $sources)
            ->where('company_id', $site->company_id)
            ->where(fn ($w) => $w->where('last_contact_at', '>=', now()->subDay())->orWhere('received_at', '>=', now()->subDay()));

        if ($digits !== null && strlen($digits) >= 10) {
            $q->where('phone_digits', 'like', '%'.substr($digits, -10));
        } elseif ($email !== '' && str_contains($email, '@')) {
            $q->whereRaw('LOWER(email) = ?', [$email]);
        } else {
            return null;
        }

        return $q->latest('received_at')->first();
    }

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
            // Five digits, or ZIP+4, as the chat sends it.
            'postal_code' => preg_match('/^\d{5}(?:-\d{4})?$/', (string) ($visitor['zip'] ?? '')) === 1 ? $visitor['zip'] : null,
            'marketing_consent_at' => $consented ? now() : null,
            'marketing_consent_text' => $consented ? mb_substr($consentText, 0, 500) : null,
            'summary' => $site->host,
            // Its own column: Grok's one-line read replaces `summary` (2026-10-19).
            'site_host' => $site->host,
            'lead_type' => $visitor['lead_type'] ?? null,
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
                // The chat's ZIP box, for a rule; null from a form or an older widget.
                'zip' => $visitor['zip'] ?? null,
                // For a rule that files it as Not a lead (looksLikeSpam).
                'looks_like_spam' => $looksLikeSpam,
                // The review faces' complaint form: sad, with what they wrote; null for an enquiry.
                'rating' => match ($lead->lead_type) {
                    'BAD_RATING' => 'sad',
                    'GOOD_RATING' => 'happy',
                    default => null,
                },
                'comment' => isset($visitor['comment']) ? mb_substr((string) $visitor['comment'], 0, 1000) : null,
            ],
        ]);

        return $lead;
    }
}
