<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNumber;
use App\Support\Leads\LeadThread;
use RuntimeException;
use Throwable;

/**
 * A WhatsApp template sent to a lead, and kept on that lead's thread - the
 * WhatsApp twin of LeadTexts::send (Jose, 2026-10-13: booking confirmations
 * "via ezbizi, whatsapp and text, and we should be able to see if that client
 * replies"). The contact is linked to THIS lead, so the reply lands here
 * (WhatsAppIntake::knownLead) and not on an older lead with the same phone.
 */
final class LeadWhatsApp
{
    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly LeadThread $thread,
    ) {}

    /**
     * Inside the lead's tenant.
     *
     * @param  array<int, string|int|float>  $vars  fills {{1}}, {{2}} in order
     *
     * @throws RuntimeException with a sentence for the screen
     */
    public function sendTemplate(Lead $lead, string $template, array $vars = [], bool $automatic = false, ?string $from = null): LeadConversation
    {
        $to = Lead::e164($lead->phone) ?? throw new RuntimeException('This lead has no phone number to send a WhatsApp message to.');

        $sent = $this->cloud->send($template, $to, $vars, 'en_US', $from);

        $remoteId = 'wa_'.($sent->wamid ?: $sent->getKey());

        $this->thread->store($lead, $remoteId, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $this->render($sent->phone_number_id, $template, array_map('strval', $vars)),
            'is_automatic' => $automatic,
        ]);
        $this->thread->refresh($lead);

        WhatsAppContact::query()->updateOrCreate(
            ['phone_number_id' => $sent->phone_number_id, 'counterparty' => $sent->counterparty],
            ['organization_id' => $lead->organization_id, 'kind' => WhatsAppContact::KIND_LEAD, 'lead_id' => $lead->getKey(), 'classified_at' => now()],
        );

        return LeadConversation::query()->where('organization_id', $lead->organization_id)->where('remote_id', $remoteId)->firstOrFail();
    }

    /** What the customer read: the approved body with its variables filled. */
    private function render(string $phoneNumberId, string $template, array $vars): string
    {
        try {
            $waba = (string) WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->value('waba_id');
            // ponytail: one template-list read per send; cache it if sends get frequent.
            $body = collect($this->cloud->templates($waba))->firstWhere('name', $template)['preview'] ?? null;
        } catch (Throwable) {
            $body = null;
        }

        if (! is_string($body) || $body === '') {
            return 'WhatsApp "'.$template.'"'.($vars === [] ? '' : ': '.implode(' | ', $vars));
        }

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $vars[(int) $m[1] - 1] ?? $m[0], $body);
    }
}
