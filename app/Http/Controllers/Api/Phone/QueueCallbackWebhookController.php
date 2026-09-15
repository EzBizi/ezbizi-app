<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCallback;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\TwiML\VoiceResponse;

/**
 * Somebody pressed 1 rather than keep holding.
 *
 * ── The promise, and where it is kept ────────────────────────────
 *
 * The wait document offers a callback; this is where the digit lands. It
 * writes the promise down and says so out loud, in that order — a caller who
 * hears "we will ring you back" and then has the row fail to save has been
 * lied to by a phone system, which is worse than never offering.
 *
 * ── Why the caller is hung up on, deliberately ───────────────────
 *
 * Because that is the whole point: they asked to stop holding. Leaving the
 * line open after promising to ring back would keep billing the call and keep
 * them listening to music they have just declined.
 *
 * ── Why any digit works ──────────────────────────────────────────
 *
 * The offer says "press 1", and the Gather takes one digit. Honouring 2, or a
 * mistyped 9, costs nothing and spares somebody who pressed the wrong key on
 * a phone in their pocket from being told they got it wrong and left holding.
 * Nothing else in the queue reads digits, so there is no other meaning to
 * collide with.
 */
class QueueCallbackWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(
        Request $request,
        string $organization,
        string $connection,
        string $number,
        string $group,
    ): Response {
        return TwilioWebhook::open(
            $request,
            $organization,
            $connection,
            $this->tenant,
            function (PhoneConnection $link) use ($request, $number, $group): Response {
                $line = PhoneNumber::query()->find($number);

                $language = $line !== null && (string) ($line->language ?? '') !== ''
                    ? (string) $line->language
                    : 'en-US';

                $caller = trim((string) $request->input('From', ''));
                $response = new VoiceResponse;

                // A caller with no number to ring back — a blocked or
                // withheld caller ID — is told rather than promised. Writing a
                // row we cannot act on would put an undeliverable obligation
                // on somebody's screen for three months.
                if (! str_starts_with($caller, '+')) {
                    $response->say(
                        'Sorry, we cannot call you back because your number is withheld. '
                        .'Please stay on the line.',
                        ['language' => $language],
                    );
                    $response->redirect($this->waitUrl($request));

                    return $this->twiml($response);
                }

                // Written BEFORE the sentence that promises it. If this throws,
                // the caller hears nothing about a callback and stays in the
                // queue, which is the state they were already in.
                PhoneCallback::query()->create([
                    'organization_id' => $this->tenant->idOrFail(),
                    'connection_id' => $link->id,
                    'number_id' => $line?->id,
                    'ring_group_id' => $group,
                    'to_e164' => $caller,
                    'position' => max(1, (int) $request->input('QueuePosition', 1)),
                    'requested_at' => now(),
                ]);

                $response->say(
                    'Thank you. We will call you back on this number as soon as '
                    .'somebody is free. You can hang up now.',
                    ['language' => $language],
                );
                $response->hangup();

                return $this->twiml($response);
            },
        );
    }

    /**
     * Back to the hold music, for the caller who cannot be rung back.
     *
     * The same URL Twilio was already looping on, reconstructed from this
     * request rather than rebuilt from parts — one fewer place for the four
     * path segments to be assembled differently.
     */
    private function waitUrl(Request $request): string
    {
        return str_replace('/callback/', '/queue/', $request->url());
    }

    private function twiml(VoiceResponse $response): Response
    {
        return response((string) $response, 200)
            ->header('Content-Type', 'text/xml');
    }
}
