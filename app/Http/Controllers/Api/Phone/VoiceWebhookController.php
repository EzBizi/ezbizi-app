<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneBlockedNumber;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\Attestation;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwilioWebhook;
use App\Support\Phone\TwimlRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;
use Twilio\TwiML\VoiceResponse;

/**
 * Public Twilio Voice URL. Signature is auth; path names the tenant.
 *
 * Inbound: TwiML from TwimlRenderer, insert a ringing PhoneCall, emit
 * phone.call.started. Dial action requests the next batch or voicemail.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TwimlRenderer $renderer,
        private readonly EventEmitter $events,
        private readonly TwilioGateway $twilio,
    ) {}

    /**
     * POST /api/v1/phone/voice/{organization}/{connection}
     *
     * Path ids are plain strings — route model binding would need a tenant
     * before the connection can be read, and this request has none yet.
     */
    public function __invoke(Request $request, string $organization, string $connection): Response
    {
        return TwilioWebhook::open(
            $request,
            $organization,
            $connection,
            $this->tenant,
            fn (PhoneConnection $row) => $this->handle($request, $row),
        );
    }

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $outbound = $this->isOutbound($request);
        $number = $this->line($request, $connection, $outbound);
        $batch = max(0, (int) $request->query('batch', 0));
        $dialStatus = $request->input('DialCallStatus');
        $dialStatus = is_string($dialStatus) ? $dialStatus : null;

        $context = $this->context($request, $connection, $outbound, $batch);

        // OUT OF THE QUEUE, and it must not go back in.
        //
        // `<Enqueue action>` lands here when a caller leaves the line, whether
        // because somebody became free or because they held for as long as the
        // group allows. Both want the same thing: try the ring group now. The
        // difference is decided a moment later by whether anybody is actually
        // reachable, which is the same question the hold loop just asked.
        //
        // `queued` is what stops it being a circle. Without it a caller
        // released to a ring that nobody answers reaches overflow again,
        // rejoins the queue at the back, and holds for ever — billed per loop,
        // and indistinguishable from hold music working.
        if ($request->input('QueueResult') !== null) {
            return $this->continueDial($request, $number, $context, null);
        }

        if ($dialStatus !== null || $batch > 0) {
            return $this->continueDial($request, $number, $context, $dialStatus);
        }

        if (! $outbound) {
            $from = $this->fromE164($request, false);

            // BEFORE anything routes. "Blocked" has to mean the handsets stay
            // quiet, and every branch below this line ends in a phone ringing
            // somewhere — a check placed after them would reject the call
            // having already rung the whole ring group.
            if (PhoneBlockedNumber::blocks($connection->id, $from)) {
                $reject = new VoiceResponse;
                $reject->reject(['reason' => 'rejected']);

                $this->recordStart(
                    $request,
                    $connection,
                    $number,
                    outbound: false,
                    blocked: true,
                );

                return $this->twiml((string) $reject);
            }

            $this->lookupQuietly($from);
        }

        $xml = $number === null
            ? (string) new VoiceResponse
            : $this->renderer->inbound($number, $context);

        $this->recordStart(
            $request,
            $connection,
            $number,
            $outbound,
            miss: $this->isClosedDocument($xml) || $this->isVoicemailDocument($xml),
            voicemail: $this->isVoicemailDocument($xml),
        );

        $this->recordKeys($request, $outbound);

        return $this->twiml($xml);
    }

    /**
     * Keep what the caller pressed. Jose, 2026-10-05: a report of "what client
     * pressed on the ivr menu". The last menu key wins (a repeat key followed
     * by 1 is a 1); typed extension digits are kept apart, because the same
     * `Digits` field carries both and only the URL says which.
     */
    private function recordKeys(Request $request, bool $outbound): void
    {
        $digits = (string) $request->input('Digits', '');
        if ($outbound || $digits === '') {
            return;
        }

        $call = $this->findCall($request);
        if ($call === null) {
            return;
        }

        $call->forceFill($request->boolean('extension')
            ? ['extension_dialed' => substr($digits, 0, 8)]
            : ['menu_key' => substr($digits, 0, 8)])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function continueDial(
        Request $request,
        ?PhoneNumber $number,
        array $context,
        ?string $dialStatus,
    ): Response {
        $xml = $number === null || $dialStatus === 'completed'
            ? (string) new VoiceResponse
            : $this->renderer->afterDial($number, $context + ['dial_status' => (string) $dialStatus]);

        $call = $this->findCall($request);

        // WHAT THE DIAL ACTUALLY DID, kept on the call.
        //
        // `DialCallStatus` was read to choose a branch and then dropped, so a
        // call that failed and a call nobody picked up left identical rows —
        // both "failed", neither saying which. On an outbound leg that is the
        // difference between "they were out" and "this could never have
        // worked", and it is the whole question when somebody reports that
        // calling out does not work.
        if ($call !== null && $dialStatus !== null && $dialStatus !== 'completed'
            && $call->error_code === null) {
            $call->forceFill([
                'error_code' => 'dial:'.$dialStatus,
                'error_message' => $call->error_message,
            ])->save();
        }

        if ($call !== null && $this->unanswered($dialStatus)
            && ($this->isVoicemailDocument($xml) || $this->isClosedDocument($xml))) {
            DB::transaction(function () use ($call, $xml) {
                $call = PhoneCall::query()->lockForUpdate()->findOrFail($call->getKey());
                $this->markMissed($call, voicemail: $this->isVoicemailDocument($xml));
            });
        }

        return $this->twiml($xml);
    }

    private function recordStart(
        Request $request,
        PhoneConnection $connection,
        ?PhoneNumber $number,
        bool $outbound,
        bool $miss = false,
        bool $voicemail = false,
        bool $blocked = false,
    ): void {
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return;
        }

        $from = $this->fromE164($request, $outbound);
        $to = $this->toE164($request);

        DB::transaction(function () use ($request, $connection, $number, $outbound, $sid, $from, $to, $miss, $voicemail, $blocked) {
            // Serialize the initial insert as well as duplicate starts.
            PhoneConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $call = PhoneCall::query()->where('twilio_sid', $sid)->lockForUpdate()->first();
            if ($call === null) {
                $call = PhoneCall::query()->create([
                    'connection_id' => $connection->id,
                    'number_id' => $number?->id,
                    'twilio_sid' => $sid,
                    'parent_sid' => $this->optionalSid($request->input('ParentCallSid')),
                    'direction' => $outbound ? PhoneCall::DIRECTION_OUTBOUND : PhoneCall::DIRECTION_INBOUND,
                    'from_e164' => $from,
                    'to_e164' => $to,
                    'status' => $blocked
                        ? PhoneCall::STATUS_BLOCKED
                        : PhoneCall::STATUS_RINGING,

                    // The SHAKEN/STIR grade exists only in this payload. No
                    // endpoint will tell us afterwards what this call was
                    // graded, so it is captured here or it is lost — which is
                    // why the Trust screen cannot be backfilled.
                    'attestation' => Attestation::fromWebhook(
                        $request->input('StirVerstat'),
                        $request->input('StirStatus'),
                    ),
                ]);

                // Recorded, but nothing downstream is told a customer is
                // calling. `phone.call.started` is what creates leads and
                // pings people; raising it here would hand the blocked caller
                // the notification they could not get by ringing.
                if (! $blocked) {
                    $this->events->emit('phone.call.started', $call, $this->payload($call));
                }
            }

            if ($miss) {
                $this->markMissed($call, voicemail: $voicemail);
            }
        });
    }

    private function markMissed(PhoneCall $call, bool $voicemail = false): void
    {
        $alreadyMissed = in_array($call->status, [
            PhoneCall::STATUS_NO_ANSWER,
            PhoneCall::STATUS_BUSY,
            PhoneCall::STATUS_FAILED,
            PhoneCall::STATUS_CANCELED,
        ], true);

        if ($alreadyMissed) {
            if ($voicemail && ! $call->voicemail) {
                $call->voicemail = true;
                $call->save();
            }

            return;
        }

        if ($call->status !== PhoneCall::STATUS_RINGING) {
            return;
        }

        $call->status = PhoneCall::STATUS_NO_ANSWER;
        if ($voicemail) {
            $call->voicemail = true;
        }
        $call->save();

        $this->events->emit('phone.call.missed', $call, $this->payload($call));
    }

    private function findCall(Request $request): ?PhoneCall
    {
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return null;
        }

        return PhoneCall::query()->where('twilio_sid', $sid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        Request $request,
        PhoneConnection $connection,
        bool $outbound,
        int $batch,
    ): array {
        $context = [
            'outbound' => $outbound,
            'batch' => $batch,
            'skip_menu' => $request->request->has('Digits'),

            // WHICH key, not merely that one was pressed. Without this a menu
            // reads its prompt, accepts a digit, and then does exactly what it
            // would have done for every other digit — which is not a menu.
            'digits' => (string) $request->input('Digits', ''),

            // AND WHAT THOSE DIGITS ARE. A menu key and an extension arrive
            // through the same `Digits` field and cannot be told apart by
            // looking at them — `0101` is a valid key press followed by three
            // more. Only the URL that collected them knows, which is why the
            // extension prompt below carries the flag in its action.
            'extension_entry' => $request->boolean('extension'),

            // Where those digits should be posted, for the prompt that asks
            // for them. Built here rather than in the renderer because the
            // webhook owns its own address — see voiceAction().
            'extension_action' => $this->voiceAction($connection, $batch, $outbound, $this->hasQueued($request)).'&extension=1',

            // THIS CALLER HAS ALREADY BEEN IN THE QUEUE, and the fact has to
            // outlive the request that learned it.
            //
            // Twilio sends `QueueResult` on the hop that leaves the queue, and
            // on that hop alone. The ring that follows posts its own result to
            // `dial_action`, which is a URL we build — so unless it is written
            // into that URL, the next request looks exactly like a first
            // attempt, reaches overflow, and puts the caller back in the line
            // they just left. That is the loop, and it bills per pass.
            'queued' => $this->hasQueued($request),

            // The group a menu key, extension or overflow sent this call to,
            // carried back on the dial action — see TwimlRenderer::carryGroup.
            // Scoped to this connection, so a forged id rings nothing.
            // And the person an extension or a one-person line was ringing.
            // Only somebody in this organization (the tenant scopes User).
            ...((string) $request->query('person', '') === ''
                || ! User::query()->whereKey((string) $request->query('person'))->exists()
                ? [] : ['ring_person' => (string) $request->query('person')]),
            ...($this->carriedGroup($request, $connection) === null ? [] : [
                'overflow_group' => $this->carriedGroup($request, $connection),
                'overflowed' => $request->boolean('overflowed'),
            ]),
            'dial_action' => $this->voiceAction(
                $connection,
                $batch + 1,
                $outbound,
                $this->hasQueued($request),
            ),
            'recording_status_callback' => $this->webhookUrl($connection, 'recording'),
            'transcription_status_callback' => $this->webhookUrl($connection, 'transcription'),
        ];

        if ($outbound) {
            $to = (string) $request->input('To', '');
            if ($to !== '') {
                $context['dial_to'] = $to;
            }

            // PICKING UP A PARKED CALL IS WHERE IT STOPS BEING PARKED.
            //
            // There is no separate un-park request, deliberately: a screen
            // that has to tell the server it picked something up is a screen
            // that leaves the board wrong whenever it crashes, closes, or
            // loses the network between the two calls. Dialling the queue IS
            // the retrieval, so this is the only moment that certainly
            // happened.
            //
            // Cleared even when the queue turns out to be empty — a caller who
            // hung up while on hold is not still parked, and leaving the row
            // stamped would put a call on the board that nobody can ever pick
            // up and nothing will ever clear.
            if (str_starts_with($to, 'park:')) {
                $this->unpark(substr($to, strlen('park:')));
            }
            // WHICH LINE THIS PERSON CALLS OUT FROM.
            //
            // The client sends the line its pad has selected, and that is
            // honoured — somebody deliberately choosing a different line to
            // call from should get it. What this adds is the fallback: when
            // the client sends nothing usable, the caller's OWN assigned
            // number is used before anything else.
            //
            // Without it an outbound call with no caller ID goes out with
            // whatever Twilio decides, which for a browser call is the client
            // identity — not a number the account owns, so the carrier
            // rejects it and the call simply fails. That is a call that never
            // connects and never says why.
            // THE CALLER ID MUST BE A NUMBER THIS ACCOUNT OWNS.
            //
            // Twilio rejects a `callerId` the account does not own or has not
            // verified, and the rejection is not a validation error we ever
            // see: the <Dial> simply fails the moment it starts. Passing the
            // client's value through unchecked is how an outbound call came
            // to end instantly with no explanation.
            //
            // Checked here rather than trusted, for the same reason the
            // browser's claim about anything else is checked — the pad's
            // selection can be stale, and a line can move between accounts.
            $callerId = $this->callerId($request);
            if ($callerId !== '' && ! $this->ownsNumber($connection, $callerId)) {
                $callerId = '';
            }

            if ($callerId === '') {
                $callerId = $this->assignedCallerId($request, $connection);
            }

            if ($callerId !== '') {
                $context['caller_id'] = $callerId;
            }
        }

        return $context;
    }

    private function line(Request $request, PhoneConnection $connection, bool $outbound): ?PhoneNumber
    {
        $e164 = $outbound ? $this->callerId($request) : (string) $request->input('To', '');
        if ($e164 === '') {
            return PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->orderBy('e164')
                ->first();
        }

        $line = PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('e164', $e164)
            ->first();

        // AN OUTBOUND CALL STILL NEEDS A VOICE even when we cannot identify
        // the line it claims to come from.
        //
        // Returning null here produced a bare `<Response/>` — no audio at all.
        // Somebody pressed Call, the phone went silent, and nothing anywhere
        // said why. The line on an outbound leg is only context (language,
        // whether to announce recording); failing to match one is a reason to
        // explain the problem, not a reason to lose the ability to speak.
        if ($line === null && $outbound) {
            return PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->orderBy('e164')
                ->first();
        }

        return $line;
    }

    private function isOutbound(Request $request): bool
    {
        // OUR OWN URL SAYS SO, and that is the most reliable signal there is.
        //
        // Twilio does not resend custom parameters to a `<Dial>` action URL,
        // so `EzBiziDirection` is absent on every request after the first.
        // The action URL is one WE generated during the outbound leg, so the
        // direction travels with it and does not depend on anything Twilio
        // chooses to echo back.
        if ($request->query('direction') === 'outbound') {
            return true;
        }

        // The client says so, and that is authoritative.
        //
        // Everything below is inference about how Twilio describes a call, and
        // inference is what failed: a browser-originated call arrives with
        // Direction "inbound" — from Twilio's side the client is calling IN —
        // so this rested on From beginning with "client:". When that did not
        // hold, an outbound call was treated as inbound and answered by the
        // line's own voicemail. Somebody pressed Call and was invited to leave
        // themselves a message.
        //
        // The inference is kept as a fallback so calls placed by an older
        // client, and Twilio's REST-initiated calls, still route correctly.
        if ($request->input('EzBiziDirection') === 'outbound') {
            return true;
        }

        $direction = (string) $request->input('Direction', '');
        $from = (string) $request->input('From', '');

        return $direction === 'outbound-api' || str_starts_with($from, 'client:');
    }

    /**
     * The number assigned to whoever is placing this call, if any.
     *
     * A browser call arrives as `client:<identity>`, and the identity is the
     * caller's user id with the hyphens taken out — so the person is
     * recoverable from the request without the client having to assert who
     * they are. Their assignment is then read from the same row that holds
     * their extension.
     *
     * Returns '' rather than guessing when the person or the assignment is
     * missing. Picking "some number on the account" would put one person's
     * calls out under another person's line, which is worse than a call that
     * does not connect: the customer rings back the wrong colleague.
     */
    private function assignedCallerId(Request $request, PhoneConnection $connection): string
    {
        $from = (string) $request->input('From', '');
        if (! str_starts_with($from, 'client:')) {
            return '';
        }

        $identity = substr($from, strlen('client:'), 32);
        if ($identity === '') {
            return '';
        }

        $extension = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->whereNotNull('number_id')
            ->get()
            // belongsTo, not equality: an identity now carries a device
            // suffix, and comparing the whole string would stop finding the
            // extension the moment a person registered a second device.
            ->first(fn (PhoneExtension $row) => $row->user !== null
                && ClientIdentity::belongsTo($identity, $row->user));

        return (string) ($extension?->number?->e164 ?? '');
    }

    /** Whether this connection actually holds the number, in E.164. */
    private function ownsNumber(PhoneConnection $connection, string $e164): bool
    {
        return PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('e164', $e164)
            ->exists();
    }

    private function callerId(Request $request): string
    {
        foreach (['CallerId', 'From', 'Caller'] as $key) {
            $value = (string) $request->input($key, '');
            if (str_starts_with($value, '+')) {
                return $value;
            }
        }

        return '';
    }

    private function fromE164(Request $request, bool $outbound): string
    {
        if ($outbound) {
            $callerId = $this->callerId($request);
            if ($callerId !== '') {
                return $this->clip($callerId);
            }
        }

        return $this->clip((string) $request->input('From', ''));
    }

    /** Lookup is optional. Miss / not enabled must not 500 the inbound answer. */
    private function lookupQuietly(string $e164): void
    {
        if ($e164 === '') {
            return;
        }

        try {
            $this->twilio->lookup($e164);
        } catch (Throwable) {
            // Show the number unmatched; never fail the webhook.
        }
    }

    private function toE164(Request $request): string
    {
        return $this->clip((string) $request->input('To', ''));
    }

    /**
     * A call leg, short enough for the column that holds it.
     *
     * ── Why the client branch names the person ──────────────────────
     *
     * `from_e164` / `to_e164` are 32 characters, which is exactly the length
     * of a user id with its hyphens removed. So `substr($value, 7, 32)` used
     * to keep precisely the person half of `client:<person>_<device>` — a
     * correct result reached by coincidence, and one that would have started
     * truncating mid-device the day either id changed length.
     *
     * Said outright now. The record keeps WHO the other end was, which is what
     * the history shows and what a customer lookup needs; which of that
     * person's devices took the call is not stored, and the column is the
     * reason.
     */
    private function clip(string $value): string
    {
        if (str_starts_with($value, 'client:')) {
            return substr(
                ClientIdentity::userPartOf(substr($value, strlen('client:'))),
                0,
                32,
            );
        }

        return substr($value, 0, 32);
    }

    private function optionalSid(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function unanswered(?string $dialStatus): bool
    {
        return ! in_array($dialStatus, ['completed', 'answered'], true);
    }

    private function isVoicemailDocument(string $xml): bool
    {
        return str_contains($xml, '<Record');
    }

    private function isClosedDocument(string $xml): bool
    {
        return str_contains($xml, 'This line is closed.');
    }

    /**
     * The URL Twilio requests when a `<Dial>` ends.
     *
     * The direction is carried IN THE URL because we are the ones who mint it,
     * and nothing else on that request can be relied on to say so. Twilio does
     * not resend custom parameters to an action URL — only its own — so the
     * `EzBiziDirection` the client sent on the first request is simply not
     * there on the second, and the call would be judged by inference again.
     * That is how an outbound call came to be answered by its own voicemail.
     */
    private function voiceAction(
        PhoneConnection $connection,
        int $batch,
        bool $outbound = false,
        bool $queued = false,
    ): string {
        $url = $this->webhookUrl($connection, 'voice').'?batch='.$batch;

        if ($outbound) {
            $url .= '&direction=outbound';
        }

        return $queued ? $url.'&queued=1' : $url;
    }

    private function carriedGroup(Request $request, PhoneConnection $connection): ?PhoneRingGroup
    {
        $id = (string) $request->query('group', '');
        if ($id === '') {
            return null;
        }

        return PhoneRingGroup::query()
            ->where('connection_id', $connection->id)
            ->find($id);
    }

    /**
     * Whether this call has already stood in the queue.
     *
     * Twilio's own word on the hop out of the queue, ours on every hop after
     * it — see the note on `queued` in context().
     */
    /**
     * Clear the park stamp for the call waiting in this queue.
     *
     * Takes the QUEUE NAME rather than an id, because that is what the desk
     * dialled and parsing it in two places is how the two spellings drift.
     */
    private function unpark(string $queue): void
    {
        $prefix = 'ezbizi-park-';
        if (! str_starts_with($queue, $prefix)) {
            return;
        }

        PhoneCall::query()
            ->whereKey(substr($queue, strlen($prefix)))
            ->whereNotNull('parked_at')
            ->update(['parked_at' => null, 'parked_by' => null]);
    }

    private function hasQueued(Request $request): bool
    {
        return $request->input('QueueResult') !== null
            || $request->boolean('queued');
    }

    private function webhookUrl(PhoneConnection $connection, string $kind): string
    {
        return url('/api/v1/phone/'.$kind.'/'.$connection->organization_id.'/'.$connection->id);
    }

    /** @return array<string, mixed> */
    private function payload(PhoneCall $call): array
    {
        return ['after' => [
            'id' => $call->id,
            'twilio_sid' => $call->twilio_sid,
            'direction' => $call->direction,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'number_id' => $call->number_id,
        ]];
    }

    private function twiml(string $xml): Response
    {
        return response($xml, Status::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
