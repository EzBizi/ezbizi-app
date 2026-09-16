<?php

namespace App\Support\Social;

use App\Models\WhatsAppMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sending on Meta's WhatsApp Business Platform, and proving they called us.
 *
 * ── Two kinds of message, and Meta polices the difference ────────
 *
 * Inside 24 hours of the customer's last message you may send free text.
 * Outside it you may send ONLY a template Meta has approved in advance, and a
 * free-text attempt is refused rather than queued. That is not a rate limit, it
 * is the product's central rule, which is why `sendTemplate` and `sendText` are
 * separate methods rather than one with a flag: the caller has to have decided
 * which situation it is in.
 *
 * ── The signature is over the RAW body ───────────────────────────
 *
 * `X-Hub-Signature-256` is an HMAC of the exact bytes Meta sent. Re-encoding a
 * decoded array and hashing that produces a different string for the same
 * message — different key order, different spacing, different escaping — and
 * the failure is silent: every webhook is rejected as forged and inbound
 * messages simply never arrive.
 */
final class WhatsAppCloud
{
    private const TIMEOUT = 20;

    /**
     * Pinned rather than 'latest'. Meta retires versions on a schedule and a
     * retired one answers errors to everything; the day it has to move is an
     * env change on the box rather than a release.
     */
    public static function endpoint(): string
    {
        return 'https://graph.facebook.com/'
            .trim((string) config('services.meta.whatsapp_version', 'v21.0'), '/');
    }

    /**
     * A template, for a message the business starts.
     *
     * @param  array<int, string>  $bodyParameters  fills {{1}}, {{2}} in order
     */
    public function sendTemplate(
        WhatsAppMessage $row,
        string $template,
        string $language = 'en_US',
        array $bodyParameters = [],
    ): WhatsAppMessage {
        return $this->deliver($row, [
            'messaging_product' => 'whatsapp',
            'to' => $row->counterparty,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                ...($bodyParameters === [] ? [] : ['components' => [[
                    'type' => 'body',
                    'parameters' => array_map(
                        fn (string $p) => ['type' => 'text', 'text' => $p],
                        array_values($bodyParameters),
                    ),
                ]]]),
            ],
        ]);
    }

    /** Free text, which Meta accepts only inside the 24-hour window. */
    public function sendText(WhatsAppMessage $row, string $text): WhatsAppMessage
    {
        return $this->deliver($row, [
            'messaging_product' => 'whatsapp',
            'to' => $row->counterparty,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(WhatsAppMessage $row, array $payload): WhatsAppMessage
    {
        $token = (string) config('services.meta.whatsapp_token');

        if ($token === '') {
            // Not marked failed. A token EzBizi has not configured says nothing
            // about the message or the recipient, and burning the row's status
            // on our own misconfiguration would send somebody looking at the
            // customer's number.
            throw new RuntimeException(
                'No WhatsApp access token is configured, so nothing was sent. That is a '
                .'configuration fault at EzBizi\'s end.'
            );
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->post(self::endpoint().'/'.$row->phone_number_id.'/messages', $payload);
        } catch (ConnectionException) {
            // Left queued on purpose. Meta being unreachable for twenty seconds
            // is not a failed message, and marking it failed would stop anybody
            // retrying something that never left.
            throw new RuntimeException('Meta did not answer, so the message was not sent.');
        }

        if ($response->failed()) {
            $because = (string) ($response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? 'Meta refused the message (HTTP '.$response->status().').');

            $row->forceFill([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'status_reason' => $because,
            ])->save();

            throw new RuntimeException($because);
        }

        $row->forceFill([
            // Meta's id for the message. Every later status arrives keyed by
            // this and by nothing else, so a row without it can never be
            // updated again.
            'wamid' => $response->json('messages.0.id'),
            'status' => WhatsAppMessage::STATUS_SENT,
            'status_reason' => null,
            'sent_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * Did Meta send this body?
     *
     * `hash_equals`, not `===`: a plain comparison returns as soon as two bytes
     * differ, and the time it takes is a measurement of how much of the
     * signature the caller got right.
     */
    public static function signed(string $rawBody, ?string $header, ?string $secret = null): bool
    {
        $secret ??= (string) config('services.meta.client_secret');

        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $rawBody, $secret),
            $header,
        );
    }
}
