<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Models\User;
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
        $tapped = $choice === self::LEAD || $choice === self::HIRING;

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

        $lead = Lead::query()->create([
            'organization_id' => $contact->organization_id,
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

        foreach ($said->where('kind', 'text') as $message) {
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
        if ($message->body === null || $message->kind !== 'text') {
            return; // a tap on the question, an image: not words for the thread
        }

        $this->thread->store($lead, (string) $message->wamid, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => $message->created_at ?? now(),
            'text' => $message->body,
        ]);
    }

    private function notify(WhatsAppContact $contact, string $kind, string $permission): void
    {
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
