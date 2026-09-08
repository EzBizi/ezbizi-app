<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\RecordingNotice;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\TwiML\VoiceResponse;

/**
 * What the person we CALLED hears, before the two legs are joined.
 *
 * ── Why this endpoint exists at all ──────────────────────────────
 *
 * The recording notice is a top-level `<Say>` in the call's TwiML. Inbound
 * that is right: Twilio runs the document on the caller's leg, so the customer
 * hears it before anybody answers.
 *
 * Outbound from the browser it is exactly inverted. The document runs on the
 * EMPLOYEE's leg — the browser is what Twilio connected — and the customer is
 * bridged afterwards by `<Dial>`. So the person being recorded heard nothing
 * and the person recording heard the warning.
 *
 * Eleven US states require every party to consent. A recording made without
 * the other party informed is one that cannot be used and should not have been
 * made, and the failure is silent: the call sounds normal, the recording
 * appears in Records, and nothing anywhere says the notice went to the wrong
 * ear.
 *
 * `<Number url="...">` is Twilio's answer — TwiML fetched and played to the
 * called party when they pick up, before bridging. This is that document.
 *
 * ── Why it is its own route and not the voice webhook ────────────
 *
 * The voice webhook decides what answers a call: hours, menus, ring groups,
 * voicemail. This says one sentence to one person. Folding it in would mean
 * every future change to that decision tree had to be reasoned about twice,
 * once for a leg where most of it cannot apply.
 *
 * Signed like every other Twilio callback, and it names the LINE as well as
 * the connection, because the sentence is the line's own — a company can set
 * one notice and a single line can differ.
 */
class WhisperWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(
        Request $request,
        string $organization,
        string $connection,
        string $number,
    ): Response {
        return TwilioWebhook::open(
            $request,
            $organization,
            $connection,
            $this->tenant,
            fn (PhoneConnection $row) => $this->say($row, $number),
        );
    }

    private function say(PhoneConnection $connection, string $numberId): Response
    {
        $response = new VoiceResponse;

        $line = PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->find($numberId);

        // A line that has gone, or one that is no longer recording by the time
        // the callee answers, gets silence rather than a stale sentence. An
        // empty document is valid TwiML and bridges immediately — saying "this
        // call may be recorded" on a call that is not being recorded is its own
        // kind of wrong.
        if ($line !== null && $line->resolvedFlag('record')) {
            $response->say(
                RecordingNotice::forNumber($line),
                ['language' => ((string) ($line->language ?? '')) ?: 'en-US'],
            );
        }

        return response((string) $response, Response::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
