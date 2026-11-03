<?php

namespace App\Support\Phone;

use App\Models\Customer;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one text from one of our lines and records it: the SMS compliance
 * gate, Twilio, the phone_messages row and `phone.sms.sent`. The Messages
 * screen and Leads' EZ Texts replies both come through here, so a text sent
 * from either is the same text everywhere.
 *
 * Who may use the line is the caller's question; this only knows the line.
 */
final class PhoneTexts
{
    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly EventEmitter $events,
        private readonly PhoneLineHealth $health,
    ) {}

    /**
     * @throws TextRefused when the line may not send, or Twilio refused
     */
    public function send(PhoneNumber $number, string $to, string $body, bool $automated = false): PhoneMessage
    {
        if (! $number->sms_outbound_allowed) {
            throw new TextRefused(PhoneNumber::SMS_REFUSAL, 403);
        }

        $connection = $number->connection;
        if ($connection === null) {
            throw new TextRefused('That line has no phone account behind it.', 404);
        }

        $from = (string) $number->e164;
        $body = SmsConsent::prepare($connection, $to, $body, $automated) ?? throw new TextRefused(SmsConsent::REFUSAL, 422);

        try {
            $sent = $this->twilio->sendMessage($connection, $from, $to, $body, $number->account_sid);
        } catch (Throwable $e) {
            $reason = $e->getMessage() !== '' ? $e->getMessage() : 'Twilio refused to send from this phone line.';
            if (PhoneLineHealth::isLineBreakingFault(null, $e)) {
                $this->health->reportBroken($connection, $reason, $number);
            }

            throw new TextRefused($reason, 502);
        }

        return DB::transaction(function () use ($number, $connection, $sent, $from, $to, $body) {
            $message = PhoneMessage::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => $sent['sid'],
                'direction' => PhoneMessage::DIRECTION_OUTBOUND,
                'from_e164' => $from,
                'to_e164' => $to,
                'body' => $body,
                'customer_id' => $to === '' ? null : Customer::query()->where('phone', $to)->value('id'),
            ]);

            $this->events->emit('phone.sms.sent', $message, ['after' => [
                'id' => $message->id,
                'twilio_sid' => $message->twilio_sid,
                'direction' => $message->direction,
                'from_e164' => $message->from_e164,
                'to_e164' => $message->to_e164,
                'body' => $message->body,
                'number_id' => $message->number_id,
                'customer_id' => $message->customer_id,
            ]]);

            return $message;
        });
    }
}
