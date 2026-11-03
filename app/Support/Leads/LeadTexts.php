<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Phone\TwilioGateway;
use RuntimeException;
use Throwable;

/**
 * A text to a lead's phone from the business's own texting line, kept in the
 * lead's thread, with the customer's answer filed back into it (Jose,
 * 2026-10-13: the booking confirmation "via ezbizi, whatsapp and text, and we
 * should be able to see if that client replies"). Any lead with a phone,
 * including a Google call, which has no reply channel of its own.
 */
final class LeadTexts
{
    /** SMS rows in the lead thread. */
    public const CHANNEL = 'SMS';

    /** How long after our text the customer's answer is still filed on the lead. */
    private const REPLY_DAYS = 30;

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly LeadThread $thread,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws RuntimeException with a sentence for the run log or the screen
     */
    public function send(Lead $lead, string $body, ?User $actor = null, bool $automatic = false): LeadConversation
    {
        $to = Lead::e164($lead->phone);
        $body = trim($body);
        if ($to === null || $body === '') {
            throw new RuntimeException($to === null ? 'This lead has no phone number to text.' : 'The message was empty, so nothing was sent.');
        }

        $number = $this->line($lead) ?? throw new RuntimeException('No texting line is cleared to send for this lead\'s company.');
        $connection = $number->connection ?? throw new RuntimeException('That texting line has no phone account behind it.');

        $body = \App\Support\Phone\SmsConsent::prepare($connection, $to, $body, $automatic) ?? throw new RuntimeException(\App\Support\Phone\SmsConsent::REFUSAL);

        try {
            $sent = $this->twilio->sendMessage($connection, (string) $number->e164, $to, $body, $number->account_sid);
        } catch (Throwable $e) {
            throw new RuntimeException('Twilio refused the text'.($e->getMessage() !== '' ? ': '.$e->getMessage() : '.'));
        }

        PhoneMessage::query()->create([
            'connection_id' => $connection->id,
            'number_id' => $number->id,
            'twilio_sid' => $sent['sid'] ?? null,
            'direction' => PhoneMessage::DIRECTION_OUTBOUND,
            'from_e164' => (string) $number->e164,
            'to_e164' => $to,
            'body' => $body,
        ]);

        $remoteId = 'sms_'.($sent['sid'] ?? uniqid());
        $this->thread->store($lead, $remoteId, [
            'channel' => self::CHANNEL,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $body,
            'is_automatic' => $automatic,
        ]);
        $this->thread->refresh($lead);

        $row = LeadConversation::query()->where('organization_id', $lead->organization_id)->where('remote_id', $remoteId)->firstOrFail();
        // conversation_id names the sender on the thread, as lead.replied does.
        $this->audit->log('lead.texted', $lead, ['from' => (string) $number->e164, 'conversation_id' => $row->id], actor: $actor);

        return $row;
    }

    /**
     * An inbound text from a phone we texted about a lead lately: filed on that
     * lead's thread. False when no lead was texted from this number.
     */
    public function absorbInbound(string $fromE164, string $body, string $sid): bool
    {
        $digits = substr(preg_replace('/\D/', '', $fromE164) ?? '', -10);
        if (strlen($digits) !== 10) {
            return false;
        }

        $lead = Lead::query()
            ->where('phone_digits', 'like', '%'.$digits)
            ->whereHas('conversations', fn ($q) => $q->where('channel', self::CHANNEL)
                ->where('participant', LeadConversation::FROM_ADVERTISER)
                ->where('occurred_at', '>=', now()->subDays(self::REPLY_DAYS)))
            ->orderByDesc('last_contact_at')
            ->first();

        if ($lead === null) {
            return false;
        }

        $this->thread->store($lead, 'sms_'.$sid, [
            'channel' => self::CHANNEL,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(),
            'text' => $body,
        ]);
        $this->thread->refresh($lead);

        return true;
    }

    /**
     * The texting line for this lead: the franchise's, then the company's,
     * then the organization's, cleared for outbound texts.
     * ponytail: first cleared line per scope; a setting if a branch has two.
     */
    private function line(Lead $lead): ?PhoneNumber
    {
        $cleared = PhoneNumber::query()->where('sms_outbound_allowed', true)->get()
            ->filter(fn (PhoneNumber $n) => $n->sms_outbound_allowed);
        $companyId = $lead->company_id ?? $lead->franchise?->company_id;

        return $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_FRANCHISE && $lead->franchise_id !== null && $n->scope_id === $lead->franchise_id)
            ?? $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_COMPANY && $companyId !== null && $n->scope_id === $companyId)
            ?? $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_ORGANIZATION);
    }
}
