<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Support\Phone\DialPlan;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\TwiML\VoiceResponse;

/**
 * What a caller hears while they hold, and the decision about when they stop.
 *
 * ── Why the hold loop is where the thinking happens ──────────────
 *
 * Twilio re-requests this URL every time the document it returns runs out of
 * verbs, for as long as the caller is in the queue. That makes it a clock
 * somebody else winds — which is the whole reason this feature needs no
 * worker, no scheduled job and no outbound calls. Every loop asks the one
 * question that matters, "has anybody become free", and answers `<Leave>`
 * when the answer is yes.
 *
 * Leaving fires the `<Enqueue action>`, which is the ordinary voice webhook
 * carrying `queued=1`, so the ring that follows is the same ring group code
 * every other call goes through.
 *
 * ── Only the caller at the front is released ─────────────────────
 *
 * A free agent is one agent. Releasing everybody who is holding would send
 * five callers at one person, four of them to be told a moment later that
 * nobody answered — and they would rejoin the line at the back, behind people
 * who arrived after them. So position 1 leaves and everybody else hears where
 * they are.
 *
 * It is not a perfect lock: the front caller is released, and if the ring
 * fails they re-enter behind whoever is now in front. That is a fairness cost
 * measured in one place in the line, and the alternative is holding a
 * reservation across two webhooks with nothing to expire it.
 *
 * ── What is deliberately NOT here ────────────────────────────────
 *
 * RingCentral offers a callback request — press 1, hang up, keep your place,
 * be rung back. `<Gather>` is permitted inside a wait document so the digit is
 * reachable, but honouring it means somewhere to keep the promise and
 * something to place the call, which is the worker this design does not have.
 * Named rather than half-built.
 */
class QueueWaitWebhookController extends Controller
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
            fn (PhoneConnection $row) => $this->handle($request, $row, $number, $group),
        );
    }

    private function handle(
        Request $request,
        PhoneConnection $connection,
        string $number,
        string $group,
    ): Response {
        $line = PhoneNumber::query()->find($number);
        $ring = PhoneRingGroup::query()->find($group);

        // The group was deleted, or its overflow was changed away from a queue
        // while somebody was standing in it. Let them out rather than hold
        // them against a setting that no longer exists.
        if ($line === null || $ring === null || $ring->overflow !== 'queue') {
            return $this->twiml((new VoiceResponse)->leave());
        }

        $waited = max(0, (int) $request->input('QueueTime', 0));
        $position = max(1, (int) $request->input('QueuePosition', 1));
        $average = max(0, (int) $request->input('AvgQueueTime', 0));

        $response = new VoiceResponse;

        // Held long enough. Leaving sends them through the action URL, where
        // `queued=1` means the queue is not offered a second time — so a line
        // with nobody on it ends at voicemail, which is where they would have
        // gone immediately without this feature.
        if ($waited >= max(1, (int) $ring->queue_max_seconds)) {
            $response->leave();

            return $this->twiml($response);
        }

        if ($position <= 1 && DialPlan::reachableUserIds($ring, $connection) !== []) {
            $response->leave();

            return $this->twiml($response);
        }

        $language = (string) ($line->language ?? '') !== ''
            ? (string) $line->language
            : 'en-US';

        if ($ring->queue_announce_position) {
            $response->say($this->position($position, (string) app(\App\Support\Settings\SettingsResolver::class)->get('phone.queue_wait_greeting', \App\Models\Organization::query()->findOrFail($line->organization_id))), ['language' => $language]);
        }

        if ($ring->queue_announce_wait && $average > 0) {
            $response->say(
                $this->estimate($average * $position),
                ['language' => $language],
            );
        }

        // ── THE OFFER, AND WHY IT WRAPS THE MUSIC ───────────────
        //
        // "Press 1 and we will call you back." The Gather holds the music, so
        // the key is live for the whole pass rather than for a moment after a
        // sentence — somebody reaching for a phone in their pocket has the
        // length of the hold music to do it.
        //
        // When nobody presses anything the Gather times out, the document
        // ends, and Twilio asks for this URL again, which is the same loop as
        // before. So the offer costs a caller who ignores it nothing at all.
        //
        // ponytail: offered whenever the queue is on, with no setting to turn
        // it off. A business that would rather people held has not asked for
        // that yet, and the promise is kept automatically, so the usual reason
        // to suppress it — nobody acts on them — does not apply here.
        $gather = $response->gather([
            'numDigits' => 1,
            'timeout' => 1,
            'action' => route('phone.queue.callback.webhook', [
                'organization' => $line->organization_id,
                'connection' => $connection,
                'number' => $line->id,
                'group' => $ring->id,
            ]),
            'method' => 'POST',
        ]);

        $gather->say(
            'Or press 1 and we will call you back without losing your place.',
            ['language' => $language],
        );

        // One pass of music, then Twilio comes back here and asks again. The
        // length of this file IS the gap between announcements, which is the
        // same thing RingCentral calls "repeat every time music ends".
        $gather->play($ring->holdMusicUrl());

        return $this->twiml($response);
    }

    /**
     * "You are third in line", counted the way a person would say it.
     *
     * Read out as an ordinal rather than "position 3", because the sentence is
     * heard once, by somebody who is already slightly annoyed, through a phone.
     */
    private function position(int $position, string $greeting): string
    {
        $words = [
            1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth',
            6 => 'sixth', 7 => 'seventh', 8 => 'eighth', 9 => 'ninth', 10 => 'tenth',
        ];

        return isset($words[$position])
            ? "{$greeting} You are {$words[$position]} in line."
            : "{$greeting} There are {$position} callers ahead of you.";
    }

    /**
     * Rounded up to whole minutes, and never to zero.
     *
     * A caller told "less than a minute" who then waits three has been lied
     * to; one told "about two minutes" who waits one is pleasantly surprised.
     * Rounding up is the direction that cannot produce the first case.
     */
    private function estimate(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes === 1
            ? 'Your estimated wait is about a minute.'
            : "Your estimated wait is about {$minutes} minutes.";
    }

    private function twiml(VoiceResponse|string $body): Response
    {
        return new Response((string) $body, 200, ['Content-Type' => 'text/xml']);
    }
}
