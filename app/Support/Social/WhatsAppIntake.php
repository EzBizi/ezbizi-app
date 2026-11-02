<?php

namespace App\Support\Social;

use App\Http\Controllers\Api\WhatsAppMediaController;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\LeadHiddenNumber;
use App\Models\Organization;
use App\Models\User;
use App\Models\SocialConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadThread;
use App\Support\Notifications\LeadNotificationText;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;

/**
 * A stranger's first WhatsApp message: ask what they want, then route it.
 *
 * Jose, 2026-10-06: a new contact gets "I need a service" / "I want to work
 * with you"; their tap decides, and until then the conversation is "(Unknown)".
 * A service request becomes a Lead (source whatsapp) and raises `lead.received`
 * like every other enquiry, so its notification comes from the same rules as
 * every other new lead. Unknown and hiring are not leads, so they are notified
 * here: unknown to whoever can open Leads, hiring to whoever can add people
 * in HR (`hr.create`).
 *
 * Staff never get here - WhatsAppStaffBot only calls this for a sender who is
 * not an active user of the number's organization.
 */
final class WhatsAppIntake
{
    /** Row ids of the two-choice question. */
    public const LEAD = 'c:lead';

    public const HIRING = 'c:hiring';

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly LeadThread $thread,
        private readonly EventEmitter $events,
        private readonly NotificationDispatcher $notifications,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * True when this answered the message and the bot should say nothing more.
     */
    public function handle(WhatsAppNumber $number, WhatsAppMessage $inbound, ?string $choice): bool
    {
        /*
         * A NUMBER THAT DOES NOT ANSWER (auto_reply off - a coexistence number
         * whose staff reply from the WhatsApp Business app, 2026-10-28): no
         * menu, no thanks. Every stranger is a lead and their words land on it.
         */
        if ($number->auto_reply === false) {
            if (($lead = $this->knownLead($number, $inbound)) !== null) {
                $this->hold($lead, $inbound);

                return true;
            }

            $contact = WhatsAppContact::firstOrCreate(
                ['phone_number_id' => $inbound->phone_number_id, 'counterparty' => $inbound->counterparty],
                ['organization_id' => $number->organization_id, 'kind' => WhatsAppContact::KIND_UNKNOWN],
            );
            if ($contact->kind === WhatsAppContact::KIND_UNKNOWN) {
                $this->classify($contact, WhatsAppContact::KIND_LEAD);
            }

            return true;
        }

        $tapped = $choice === self::LEAD || $choice === self::HIRING;

        /*
         * A CUSTOMER WE ALREADY KNOW: replying to a booking confirmation, or
         * any lead whose phone this is (Jose, 2026-10-13: "we should be able to
         * see if that client replies"). Their words go in that lead's thread
         * and staff answer from Leads - not "how can we help?", not a menu.
         */
        if (! $tapped && $this->menuWord($inbound) === false && ($lead = $this->knownLead($number, $inbound)) !== null) {
            $this->hold($lead, $inbound);

            return true;
        }

        // Switched off in AI Assistance settings: nobody is asked. A tap on a
        // question sent before it was switched off is still honoured.
        if (! $tapped && ! $this->settings->get('ai_assistance.ask_new_contacts', Organization::findOrFail($number->organization_id))) {
            return false;
        }

        $contact = WhatsAppContact::firstOrCreate(
            ['phone_number_id' => $inbound->phone_number_id, 'counterparty' => $inbound->counterparty],
            ['organization_id' => $number->organization_id, 'kind' => WhatsAppContact::KIND_UNKNOWN],
        );

        if ($tapped) {
            $kind = $choice === self::LEAD ? WhatsAppContact::KIND_LEAD : WhatsAppContact::KIND_HIRING;

            // A second tap on an old question changes nothing: the first answer
            // already made a lead or told HR, and doing it twice is noise.
            if ($contact->kind === WhatsAppContact::KIND_UNKNOWN) {
                $this->classify($contact, $kind);
            }

            $this->text($number, $contact->counterparty, $kind === WhatsAppContact::KIND_LEAD
                ? "\u{2705} *Thanks!*\n\n\u{1F4E8} We passed your request to our team.\n\u{1F4DE} Someone will reach out shortly.\n\n\u{1F4CB} Text *MENU* any time for more."
                : "\u{1F91D} *Thanks for your interest in working with us!*\n\n\u{1F4E8} We passed your details to our hiring team.\n\n\u{1F4CB} Text *MENU* any time for more.");

            return true;
        }

        if ($contact->kind === WhatsAppContact::KIND_LEAD) {
            $lead = Lead::query()->find($contact->lead_id);

            if ($lead !== null) {
                $this->hold($lead, $inbound);
            }
        }

        if (! $contact->wasRecentlyCreated) {
            return false;
        }

        $this->notify($contact, WhatsAppContact::KIND_UNKNOWN, 'leads.view');
        $this->ask($number, $contact->counterparty);

        return true;
    }

    /** "MENU" and friends still reach the bot, as the thanks message promises. */
    private function menuWord(WhatsAppMessage $inbound): bool
    {
        return in_array(mb_strtolower(trim((string) $inbound->body)), ['menu', 'hi', 'hello', 'hey', 'hola', 'start', 'help', '?'], true);
    }

    /**
     * The lead this sender already is: the one their WhatsApp contact made, or
     * the newest lead of this business with their phone (last 10 digits). The
     * contact is created or linked so the next message is one lookup.
     */
    private function knownLead(WhatsAppNumber $number, WhatsAppMessage $inbound): ?Lead
    {
        $contact = WhatsAppContact::query()
            ->where('phone_number_id', $inbound->phone_number_id)
            ->where('counterparty', $inbound->counterparty)
            ->first();

        if ($contact !== null) {
            return $contact->kind === WhatsAppContact::KIND_LEAD ? Lead::query()->find($contact->lead_id) : null;
        }

        $last10 = substr((string) Lead::digits($inbound->counterparty), -10);

        // ponytail: a LIKE on phone_digits scans the org's leads; index a
        // last-10 column if WhatsApp volume ever makes this slow.
        $lead = strlen($last10) === 10
            ? Lead::query()->where('phone_digits', 'like', '%'.$last10)->latest('received_at')->first()
            : null;

        if ($lead !== null) {
            WhatsAppContact::create([
                'phone_number_id' => $inbound->phone_number_id,
                'counterparty' => $inbound->counterparty,
                'organization_id' => $number->organization_id,
                'kind' => WhatsAppContact::KIND_LEAD,
            ])->forceFill(['lead_id' => $lead->getKey(), 'classified_at' => now()])->save();
        }

        return $lead;
    }

    private function classify(WhatsAppContact $contact, string $kind): void
    {
        $contact->forceFill(['kind' => $kind, 'classified_at' => now()])->save();

        if ($kind === WhatsAppContact::KIND_HIRING) {
            $this->notify($contact, $kind, 'hr.create');

            return;
        }

        $said = WhatsAppMessage::query()
            ->where('phone_number_id', $contact->phone_number_id)
            ->where('counterparty', $contact->counterparty)
            ->where('direction', WhatsAppMessage::DIRECTION_IN)
            ->oldest()
            ->get();

        // The company of the number it arrived on (Jose, 2026-11-02): without
        // it A-1's WhatsApp leads showed in every company's Leads.
        $connection = SocialConnection::withoutGlobalScopes()->find(
            WhatsAppNumber::withoutGlobalScopes()->where('phone_number_id', $contact->phone_number_id)->value('social_connection_id'));

        $lead = Lead::query()->create([
            'organization_id' => $contact->organization_id,
            'company_id' => $connection?->scope_type === SocialConnection::SCOPE_COMPANY ? $connection->scope_id : null,
            'franchise_id' => $connection?->leads_franchise_id
                ?? ($connection?->scope_type === SocialConnection::SCOPE_FRANCHISE ? $connection->scope_id : null),
            'connection_id' => $connection?->getKey(),
            'source' => Lead::SOURCE_WHATSAPP,
            'remote_id' => $contact->counterparty,
            'phone' => '+'.$contact->counterparty,
            'phone_digits' => Lead::digits('+'.$contact->counterparty),
            // What they first said, before the question - the enquiry itself.
            'summary' => LeadNotificationText::clip($said->firstWhere('kind', 'text')?->body, 255) ?: null,
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);

        $contact->forceFill(['lead_id' => $lead->getKey()])->save();

        foreach ($said as $message) { // hold() keeps words and files only
            $this->hold($lead, $message);
        }

        $this->events->emitAfterCommit('lead.received', $lead, [
            'after' => $lead->only(['source', 'contact_name', 'phone', 'category', 'received_at']) + [
                'kind' => $lead->kind(),
            ],
        ]);
    }

    private function hold(Lead $lead, WhatsAppMessage $message): void
    {
        // Words, or a file (voice note, photo, video, document) streamed on
        // demand. A tap on the question is neither.
        $media = $message->media_id !== null && $message->kind !== 'interactive';
        if (! $media && ($message->body === null || $message->kind !== 'text')) {
            return;
        }

        $this->thread->store($lead, (string) $message->wamid, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => $message->created_at ?? now(),
            'text' => $message->body,
            'attachments' => $media ? [WhatsAppMediaController::ref((string) $message->phone_number_id, (string) $message->media_id, (string) $message->kind)] : null,
        ]);
    }

    private function notify(WhatsAppContact $contact, string $kind, string $permission): void
    {
        // A hidden number (Leads, 2026-10-29) rings nobody: the bell carries it.
        if (LeadHiddenNumber::covers('+'.$contact->counterparty)) {
            return;
        }

        // ponytail: every active user is loaded and asked; an org with
        // thousands of users wants a permission query here instead.
        $audience = User::query()->where('is_active', true)->get()
            ->filter(fn (User $user) => $user->hasPermission($permission))
            ->modelKeys();

        if ($audience === []) {
            return;
        }

        $this->notifications->dispatch(
            title: LeadNotificationText::whatsappHeadline($kind),
            body: LeadNotificationText::details('+'.$contact->counterparty, null, null, null),
            subject: $contact,
            source: 'system',
            userIds: $audience,
        );
    }

    private function ask(WhatsAppNumber $number, string $to): void
    {
        $body = "\u{1F44B} *Thanks for writing to us!*\n\nHow can we help? Choose below \u{1F447}";

        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button' => 'Choose',
                    // A list, not two buttons: a button title stops at 20
                    // characters and Jose's wording is 23.
                    'sections' => [[
                        'title' => 'How can we help?',
                        'rows' => [
                            ['id' => self::LEAD, 'title' => 'I need a service'],
                            ['id' => self::HIRING, 'title' => 'I want to work with you'],
                        ],
                    ]],
                ],
            ],
        ], $body);
    }

    private function text(WhatsAppNumber $number, string $to, string $body): void
    {
        $this->cloud->replyFreeForm($number, $to, ['type' => 'text', 'text' => ['body' => $body]], $body);
    }
}
