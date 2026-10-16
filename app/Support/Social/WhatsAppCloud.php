<?php

namespace App\Support\Social;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
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
 * is the product's central rule, which is why `send` and `replyFreeForm` are
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

    /**
     * Send a template to a customer, row and all. The call an automation makes.
     *
     * ── Why this owns the row instead of taking one ──────────────
     *
     * `sendTemplate` takes a `WhatsAppMessage` the caller built, which is right
     * for a reply screen that already has one and wrong for everybody else.
     * Three facts have to be true about that row - `phone_number_id` is the
     * WhatsAppNumber's provider id and NOT the display number, `counterparty`
     * is digits with no leading `+`, and the row exists BEFORE the attempt -
     * and every caller that builds its own is a copy of those three facts that
     * goes stale silently the day one of them changes.
     *
     * ── Created before the attempt, deliberately ─────────────────
     *
     * Same shape as the automation engine's outbox: write the intent, then
     * try. A process that dies mid-send then leaves a `queued` row saying
     * something was owed, rather than leaving silence that nobody can tell
     * from a rule that never fired.
     *
     * ── The number is resolved here, under the tenant scope ──────
     *
     * Not passed in. `WhatsAppNumber` is tenant-scoped, so asking it inside the
     * caller's tenant context is what makes it impossible to send from another
     * organization's line by passing the wrong id.
     *
     * @param  array<int, string|int|float>  $parameters  fills {{1}}, {{2}} in order
     * @param  string|null  $from  which of our numbers to send from, when the
     *                             organization holds more than one. Required
     *                             in that case: see numberToSendFrom().
     *
     * @throws RuntimeException when the tenant has no number, the recipient is
     *                          not a number we can be sure of, or Meta refuses
     */
    public function send(
        string $template,
        string $recipient,
        array $parameters = [],
        string $language = 'en_US',
        ?string $from = null,
        ?string $idempotencyKey = null,
    ): WhatsAppMessage {
        /*
         * A key already sent (or still owed) answers with that row and sends
         * nothing. A failed one is retried: the person never got it. Scoped by
         * the tenant scope, so one org's key can never return another's row.
         */
        if ($idempotencyKey !== null) {
            $sent = WhatsAppMessage::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($sent !== null && $sent->status !== WhatsAppMessage::STATUS_FAILED) {
                return $sent;
            }

            $sent?->forceFill(['idempotency_key' => null])->save();
        }

        $row = $this->rowFor($recipient, $template, $from);

        if ($idempotencyKey !== null) {
            $row->forceFill(['idempotency_key' => $idempotencyKey])->save();
        }

        return $this->sendTemplate($row, $template, $language, array_map('strval', $parameters));
    }

    /**
     * A free-form reply - text or an interactive menu - row and all.
     *
     * FREE-FORM MEANS INSIDE THE 24-HOUR WINDOW, and that is the only reason
     * this is safe to call without a template: the one caller is the staff bot,
     * answering a message the person sent seconds ago.
     *
     * ── Outside the window this SUCCEEDS and then fails ──────────
     *
     * Measured 2026-09-16, because Jose asked whether the template rule was
     * really as absolute as it sounds. A plain text sent 75 minutes after the
     * window closed came back **HTTP 200 with a message id**, so `deliver()`
     * wrote `status = sent` and returned normally. Meta failed it
     * asynchronously and said so only on the webhook:
     *
     *     status         failed
     *     status_reason  Re-engagement message
     *
     * So the refusal is NOT a thrown exception and there is nothing here to
     * catch. The synchronous answer is an acknowledgement that the request was
     * well formed, not a statement that the message will be delivered - and a
     * caller that reports "sent" off this method's return value reports a
     * success that is already wrong by the time anybody reads it.
     *
     * This is why the automation action uses `send()` and templates. A rule
     * fires when a job is booked or an invoice goes unpaid, which is never
     * inside the window, and a free-text action would have passed every test
     * anyone wrote - the API says 200 - while silently failing in production.
     *
     * No window check is done here on purpose. Our own `whatsapp_messages`
     * inbound rows only go back to the day the webhook was wired, so a local
     * clock would refuse conversations Meta would have allowed, and a false
     * refusal on a live reply is worse than a recorded failure. The webhook is
     * the authority and it records the real outcome.
     *
     * Takes the resolved number rather than a display number to look up,
     * because the bot already knows which of our numbers the message arrived
     * on; asking it to name one again would be a second chance to name the
     * wrong one.
     *
     * @param  array<string, mixed>  $message  the `type` and its body, e.g.
     *                                         ['type' => 'text', 'text' => [...]]
     */
    public function replyFreeForm(
        WhatsAppNumber $number,
        string $to,
        array $message,
        ?string $bodyForTheRecord = null,
    ): WhatsAppMessage {
        $row = WhatsAppMessage::create([
            'organization_id' => $number->organization_id,
            'phone_number_id' => $number->phone_number_id,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'counterparty' => preg_replace('/\D+/', '', $to) ?? '',
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'kind' => (string) ($message['type'] ?? 'text'),
            // What the person was shown, so the history reads as a
            // conversation rather than as a list of message ids.
            'body' => $bodyForTheRecord,
        ]);

        return $this->deliver($row, [
            'messaging_product' => 'whatsapp',
            'to' => $row->counterparty,
            ...$message,
        ]);
    }

    /**
     * The APPROVED templates on a WABA, with the parameter count each needs.
     *
     * For the automation builder: a rule fires when a job is booked or an
     * invoice goes unpaid, which is never inside the 24-hour window, so the
     * only thing such a rule can send is a template. A picker needs to know
     * which ones exist and how many blanks each has.
     *
     * ── Counting parameters by reading the text ──────────────────
     *
     * Meta does NOT return a parameter count. It returns the template's text
     * with `{{1}}`, `{{2}}` in it, and the count is the HIGHEST number
     * present - not how many distinct ones appear. A body reading
     * "{{1}} owes {{3}}" is a three-parameter template with a gap, and
     * counting occurrences would say two and send an array Meta rejects.
     *
     * PENDING and REJECTED are filtered out here rather than by the caller.
     * Offering a template that has not been approved yet builds a rule that
     * looks right in the builder and fails at the moment it matters.
     *
     * @return array<int, array{name: string, language: string, category: string, parameters: int, preview: string}>
     */
    public function templates(string $wabaId): array
    {
        $token = (string) config('services.meta.whatsapp_token');

        if ($token === '') {
            throw new RuntimeException(
                'No WhatsApp access token is configured, so the template list could not be read.'
            );
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(self::endpoint().'/'.$wabaId.'/message_templates', [
                    'fields' => 'name,status,category,language,components',
                    'limit' => 100,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta did not answer, so the template list could not be read.');
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? 'Meta refused the template list (HTTP '.$response->status().').'));
        }

        $out = [];

        foreach ((array) $response->json('data', []) as $template) {
            if (($template['status'] ?? '') !== 'APPROVED') {
                continue;
            }

            $body = '';
            $highest = 0;

            foreach ((array) ($template['components'] ?? []) as $component) {
                $text = (string) ($component['text'] ?? '');

                if (($component['type'] ?? '') === 'BODY') {
                    $body = $text;
                }

                // Header and body both take parameters and they share one
                // numbering, so both are scanned.
                if (preg_match_all('/\{\{(\d+)\}\}/', $text, $m)) {
                    $highest = max($highest, ...array_map('intval', $m[1]));
                }
            }

            $out[] = [
                'name' => (string) ($template['name'] ?? ''),
                'language' => (string) ($template['language'] ?? ''),
                'category' => (string) ($template['category'] ?? ''),
                'parameters' => $highest,
                'preview' => $body,
            ];
        }

        return $out;
    }

    /**
     * WHICH of our numbers to send from, or a refusal to choose.
     *
     * ── This used to be `->first()` and that was a defect ────────
     *
     * One organization can hold several numbers - A1-Group holds two today,
     * "EzBizi" and "EzBizi Reports" - and `first()` returned whichever sorted
     * first, which was the wrong one for the caller that needed Reports.
     *
     * The failure had no symptom. The message sends, it arrives, the row says
     * `sent`, and the only evidence of the mistake is the sender's name in a
     * chat window nobody on our side is looking at. A financial report going
     * out from the customer-facing line is not something anyone would notice
     * until a customer asked about it.
     *
     * So: with more than one number and nothing said, this REFUSES. A default
     * here would be a guess presented as a fact, and the cost of guessing wrong
     * is invisible while the cost of refusing is a clear message at the call
     * site. One number and nothing said is unambiguous, so that still works.
     *
     * `$from` is a phone number, however it is written - "+1 877 450-8572",
     * "8774508572" - and not the display name, which anybody can rename.
     */
    private function numberToSendFrom(?string $from): WhatsAppNumber
    {
        $numbers = WhatsAppNumber::query()->orderBy('display_number')->get();

        if ($numbers->isEmpty()) {
            throw new RuntimeException(
                'No WhatsApp number is attached to this organization, so there is nothing to '
                .'send from.'
            );
        }

        if ($from === null) {
            if ($numbers->count() > 1) {
                throw new RuntimeException(
                    'This organization has more than one WhatsApp number ('
                    .$numbers->pluck('display_number')->implode(', ')
                    .'), so the one to send from has to be named.'
                );
            }

            return $numbers->first();
        }

        $wanted = preg_replace('/\D+/', '', $from) ?? '';

        $match = $numbers->first(
            fn (WhatsAppNumber $n) => preg_replace('/\D+/', '', (string) $n->display_number) === $wanted,
        );

        if ($match === null) {
            throw new RuntimeException(
                'No WhatsApp number matching '.$from.' is attached to this organization. '
                .'It holds: '.$numbers->pluck('display_number')->implode(', ').'.'
            );
        }

        return $match;
    }

    /**
     * The row every outbound send starts from, written before the attempt.
     *
     * The three facts it encodes - provider id not display number, digits with
     * no `+`, written first - are the ones a second copy would drift on.
     */
    private function rowFor(
        string $recipient,
        string $template,
        ?string $from,
    ): WhatsAppMessage {
        $number = $this->numberToSendFrom($from);

        /*
         * Reused rather than rewritten, and from a Thumbtack class on purpose:
         * it already returns null instead of guessing, and a second normaliser
         * is a second set of rules about what "10 digits" means. A wrong number
         * here sends a customer's booking confirmation to a stranger's handset.
         */
        $e164 = ThumbtackAssociatePhoneNumbers::e164($recipient);

        if ($e164 === null) {
            throw new RuntimeException(
                'That is not a phone number WhatsApp can be sent to, so nothing was sent.'
            );
        }

        return WhatsAppMessage::create([
            'organization_id' => $number->organization_id,
            'phone_number_id' => $number->phone_number_id,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            // Meta wants the digits without the `+`, and every inbound `from`
            // it sends us is in that shape too - so both directions of a
            // conversation key the same way and thread together.
            'counterparty' => ltrim($e164, '+'),
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'kind' => 'template',
            'template_name' => $template,
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
