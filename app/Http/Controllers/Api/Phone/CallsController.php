<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Support\Phone\ClientIdentity;
use App\Models\User;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;
use App\Models\PhoneNumber;
use Twilio\TwiML\VoiceResponse;

/**
 * Call records for granted numbers (spec §11). Playback streams Twilio media
 * through EzBizi with the connection credentials — never a bare Twilio URL.
 */
class CallsController extends Controller
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly TwilioGateway $twilio,
        private readonly TwimlRenderer $renderer,
    ) {}

    /**
     * POST /api/v1/phone/calls/bridge — ring my own phone, then the customer.
     *
     * ── Why this exists ─────────────────────────────────────────────
     *
     * There is no Twilio Voice SDK for Windows, macOS, Linux, Android or iOS
     * inside this app — the softphone is a browser script — so on every
     * platform except the web the Dial pad could not place a call at all, and
     * said so. That is four of five platforms told the phone system is not for
     * them.
     *
     * This needs no SDK. Twilio rings the handset the person already has,
     * they answer, and Twilio dials the customer and bridges the two. It is an
     * HTTP request, so it works identically on desktop, Android, iOS and the
     * web — including in a browser whose microphone was refused.
     *
     * ── What the customer sees ──────────────────────────────────────
     *
     * The company line, not the employee's mobile. That is the whole point of
     * the callerId on the inner Dial: without it the employee's personal
     * number is what gets published to every customer they ring, and it cannot
     * be taken back afterwards.
     *
     * ── Why the recording notice is on the INNER dial ───────────────
     *
     * The employee answers first, so anything said before the Dial reaches
     * only them. The customer is the dialled party, and a consent announcement
     * the customer never hears is not consent. It rides the Number's own url
     * as a whisper, which is the mechanism Twilio provides for exactly this.
     */
    public function bridge(Request $request): JsonResponse
    {
        $input = $request->validate([
            'number_id' => ['required', 'uuid'],
            'to' => ['required', 'string', 'max:32'],
        ]);

        $number = PhoneNumber::query()->findOrFail($input['number_id']);
        $user = $request->user();

        if (! $this->access->mayView($user, $number)) {
            abort(Status::HTTP_FORBIDDEN);
        }

        // The person's own handset. Without it there is nothing to ring, and
        // guessing — the company line, a colleague's — would either loop the
        // call back into EzBizi or ring somebody else's phone.
        $mine = trim((string) $user->phone);
        if ($mine === '') {
            return response()->json([
                'message' => 'Add your own phone number to your profile first. '
                    .'This connects a call by ringing you, then the number you '
                    .'are calling.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $number->connection;
        if ($connection === null) {
            return response()->json([
                'message' => 'That line has no phone account behind it.',
            ], Status::HTTP_NOT_FOUND);
        }

        $to = (string) $input['to'];

        // SHAPE only, and it is worth being clear what that does not cover.
        //
        // `+3057242199` — the number every outbound call went to for weeks —
        // passes this, because it IS structurally valid E.164. What was wrong
        // with it is that +305 is not a country, and seeing that needs a table
        // of real country codes rather than a pattern. The country code is
        // fixed where it is knowable: the dial pad, which knows ten digits
        // typed there means North America.
        //
        // This is the backstop for what is not a phone number at all.
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
            return response()->json([
                'message' => 'That does not look like a phone number. Include '
                    .'the country code, or type the ten digits and EzBizi will '
                    .'add it.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $call = $this->twilio->createCall(
                $connection,
                (string) $number->e164,
                $mine,
                $this->bridgeTwiml($number, $to),
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? 'Twilio refused the call: '.$e->getMessage()
                    : 'Twilio refused the call.',
            ], Status::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => [
            'sid' => $call['sid'],
            'ringing' => $mine,
            'calling' => $to,
            'from' => (string) $number->e164,
        ]], Status::HTTP_CREATED);
    }

    /** What the employee's phone runs once they answer. */
    private function bridgeTwiml(PhoneNumber $number, string $to): string
    {
        $response = new VoiceResponse;

        // Said to the EMPLOYEE, so they know which call this is before it
        // connects — their phone rang from a company number with no context.
        $response->say(
            'Connecting you.',
            ['language' => 'en-US'],
        );

        $dial = $response->dial(null, [
            'callerId' => (string) $number->e164,
            'answerOnBridge' => true,
            'timeout' => 30,
        ]);

        $dial->number($to);

        return (string) $response;
    }

    /** GET /api/v1/phone/calls */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $numberIds = $this->access->readable($request->user())->pluck('id')->all();

        $query = PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->orderByDesc('created_at');

        // The desk asks for this the moment a call ends, to know which row to
        // ask about. Only THIS person's calls: two people hang up at once and
        // otherwise each could be handed the other's.
        if ($request->boolean('untagged')) {
            $query->whereNull('tag_id')->where('user_id', $request->user()->id);
        }

        // Voicemails for one box.
        //
        // A box is not on the call — it is on the LINE the call arrived on, so
        // this resolves the lines pointing at that box and asks for their
        // calls. Doing it here rather than on screen keeps the Voicemails view
        // from having to know how a box is attached to anything.
        // Every voicemail, however it is filed.
        //
        // The Voicemail screen opens on this rather than on a box, because a
        // box only holds messages once a LINE points at it — and a box nothing
        // points at is empty forever while the messages sit right there under
        // no box at all.
        if ($request->boolean('voicemail_only')) {
            $query->where('voicemail', true);
        }

        $boxId = (string) $request->query('box_id', '');
        if ($boxId !== '') {
            // RESOLVED, not the raw column.
            //
            // A line's voicemail box is `number ?? connection` — the company
            // sets one default and a line may override it, which is the whole
            // point of company defaults and is what routing actually obeys
            // (`PhoneNumber::resolved`). This filter read the column alone, so
            // a company that set its default centrally instead of on every
            // line had EVERY box show empty while All voicemails showed the
            // messages sitting in them.
            //
            // Jose reported it as clicking A-1 Chimney Mailbox and seeing
            // nothing. Two answers to "which box does this line use", and the
            // screen was asking the one that does not decide anything.
            $query->where('voicemail', true)->whereIn(
                'number_id',
                PhoneNumber::query()
                    ->where(function ($line) use ($boxId) {
                        $line->where('voicemail_box_id', $boxId)
                            ->orWhere(function ($default) use ($boxId) {
                                // '' is a cleared field, not a choice — the
                                // same reading `resolved()` takes.
                                $default->where(fn ($unset) => $unset
                                    ->whereNull('voicemail_box_id')
                                    ->orWhere('voicemail_box_id', ''))
                                    ->whereIn(
                                        'connection_id',
                                        PhoneConnection::query()
                                            ->where('default_voicemail_box_id', $boxId)
                                            ->pluck('id'),
                                    );
                            });
                    })
                    ->pluck('id'),
            );
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $query->limit(min($perPage, 200));
        }

        $calls = $query->get();

        $customerIds = $this->customerIdsByPhone($calls);
        $colleagues = $this->colleagueNames($calls);

        $rows = $calls
            ->map(fn (PhoneCall $call) => $this->row($call, $customerIds, $colleagues))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/phone/calls/transfer — move the call I am on to my other devices.
     *
     * ── What this is for ─────────────────────────────────────────────
     *
     * Jose asked for it by name: a call answered at the desk should be able to
     * follow you to your phone on the way out of the door, the way RingCentral
     * does it. Before this the only way to move a call was to hang up on the
     * customer and ring them back from the other device.
     *
     * ── Why the CUSTOMER's leg is the one that moves ────────────────
     *
     * A live call is two legs bridged by a `<Dial>`. Redirecting the customer's
     * leg at new TwiML ends that Dial — so the handset holding the call drops,
     * the customer stays connected to Twilio throughout, and the new document
     * rings whatever it names. Redirecting the other leg would take the phone
     * away from the person holding it and leave the customer bridged to
     * nothing.
     *
     * ── Why the client does not say which call ──────────────────────
     *
     * It cannot, honestly. A browser knows its own leg's SID, not the
     * customer's, and the row is keyed on the customer's. The server knows who
     * is asking and which call they are on — `answered_by` for one that rang
     * them, `user_id` for one they placed — so it looks it up rather than
     * trusting a SID from a client that would have to be told it in the first
     * place.
     *
     * ── Every OTHER device, not a chosen one ────────────────────────
     *
     * The asking device names itself and is left out; everything else this
     * person has registered rings at once, and the first to answer takes the
     * call. That is what "move it to my phone" means when somebody is walking
     * away from a desk, and it needs no picker on a screen they are no longer
     * looking at.
     */
    public function transfer(Request $request): JsonResponse
    {
        $input = $request->validate([
            // Which device is asking, so it is not one of the ones rung. A
            // client too old to send it still transfers; it just also rings
            // itself, which is visible and harmless rather than silent.
            'from_device_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();

        $call = PhoneCall::query()
            ->where('status', PhoneCall::STATUS_IN_PROGRESS)
            ->where(fn ($q) => $q
                ->where('answered_by', $user->id)
                ->orWhere('user_id', $user->id))
            ->latest('created_at')
            ->first();

        if ($call === null) {
            return response()->json([
                'message' => 'You are not on a call right now.',
            ], Status::HTTP_CONFLICT);
        }

        $connection = $call->connection;
        $number = $call->number_id === null ? null : PhoneNumber::query()->find($call->number_id);

        if ($connection === null || $number === null) {
            return response()->json([
                'message' => 'That call is not on a line EzBizi can move it from.',
            ], Status::HTTP_CONFLICT);
        }

        // NO permission check on the line, deliberately. The query above only
        // finds a call this person answered or placed, so they are already on
        // it — a grant check here could only refuse somebody their own live
        // call, which is a guard inventing a problem rather than catching one.

        $targets = $this->otherDevices(
            $connection,
            (string) $user->id,
            (string) ($input['from_device_id'] ?? ''),
        );

        if ($targets === []) {
            return response()->json([
                'message' => 'No other device of yours is signed in to take it.',
            ], Status::HTTP_CONFLICT);
        }

        try {
            $this->twilio->redirectCall(
                $connection,
                (string) $call->twilio_sid,
                $this->renderer->transfer($number, $targets),
            );
        } catch (\Throwable) {
            // The commonest reason is that the call ended while somebody was
            // reaching for the button. Said as a fact about the call rather
            // than as a server error, because nothing is broken.
            return response()->json([
                'message' => 'That call has already ended.',
            ], Status::HTTP_CONFLICT);
        }

        return response()->json(['data' => [
            'call_id' => $call->id,
            'ringing' => count($targets),
        ]]);
    }

    /**
     * POST /api/v1/phone/calls/park
     *
     * Set the call I am on down, so anybody can pick it up.
     *
     * ── Why this is a redirect and not a transfer ────────────────────
     *
     * Same mechanism as [transfer] and for the same reason given there: this
     * device knows its own leg, not the customer's, and the customer's is the
     * one that has to go on holding. Redirecting our own would take the phone
     * away from the person holding it and leave the customer bridged to
     * nothing.
     *
     * The difference from a transfer is who it is handed to: a transfer names
     * the devices to ring, a park names nobody. The caller waits in a queue of
     * their own and the first colleague to dial into it gets them.
     *
     * ── Why there are no park slots ──────────────────────────────────
     *
     * RingCentral has numbered Park Locations because a desk phone has buttons
     * and a button needs a number. Nothing here has buttons, so the queue is
     * named after the call and the screen lists the calls themselves — which
     * is also the more useful thing to read. You pick up "the call from
     * +1 305…", not "slot 3", and there is no slot to run out of or collide on.
     */
    public function park(Request $request): JsonResponse
    {
        $user = $request->user();

        $call = PhoneCall::query()
            ->where('status', PhoneCall::STATUS_IN_PROGRESS)
            ->whereNull('parked_at')
            ->where(fn ($q) => $q
                ->where('answered_by', $user->id)
                ->orWhere('user_id', $user->id))
            ->latest('created_at')
            ->first();

        if ($call === null) {
            return response()->json([
                'message' => 'You are not on a call right now.',
            ], Status::HTTP_CONFLICT);
        }

        $connection = $call->connection;
        if ($connection === null) {
            return response()->json([
                'message' => 'That call is not on a line EzBizi can park it from.',
            ], Status::HTTP_CONFLICT);
        }

        // NO permission check on the line, deliberately, for the reason
        // transfer() gives: the query above only finds a call this person
        // answered or placed, so they are already on it.

        try {
            $this->twilio->redirectCall(
                $connection,
                (string) $call->twilio_sid,
                $this->renderer->park($call),
            );
        } catch (\Throwable) {
            return response()->json([
                'message' => 'That call has already ended.',
            ], Status::HTTP_CONFLICT);
        }

        // Stamped AFTER the redirect, not before. A row that says parked for a
        // call Twilio refused to move is a call on the board that nobody can
        // pick up, and the way out of it is a database edit.
        $call->forceFill([
            'parked_at' => now(),
            'parked_by' => $user->id,
        ])->save();

        return response()->json(['data' => [
            'call_id' => $call->id,
            'queue' => $call->parkQueueName(),
        ]]);
    }

    /**
     * GET /api/v1/phone/calls/parked
     *
     * Who is on hold, and who put them there.
     *
     * Scoped to the lines this person can see, so parking is shared by the
     * people who share the phone rather than by the whole organization —
     * a caller parked on A-1 Chimney's line is not a call Everdoors can pick up.
     */
    public function parked(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        // readable(), not visibleTo(): the same lines the calls list uses.
        // A parked call belongs to whoever shares that phone, so it follows
        // the GRANT rather than who may edit settings — otherwise an admin
        // with no line sees calls they cannot pick up, and somebody holding
        // the line does not.
        $numberIds = $this->access->readable($request->user())->pluck('id')->all();

        $calls = PhoneCall::query()
            ->whereNotNull('parked_at')
            ->whereIn('number_id', $numberIds)
            ->orderBy('parked_at')
            ->limit(25)
            ->get();

        $names = User::query()
            ->whereIn('id', $calls->pluck('parked_by')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json(['data' => $calls->map(fn (PhoneCall $call) => [
            'id' => $call->id,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'parked_at' => optional($call->parked_at)->toIso8601String(),
            'parked_by' => $call->parked_by,
            'parked_by_name' => $names[$call->parked_by] ?? null,

            // What the desk dials to fetch them. Sent rather than assembled on
            // the client, so the naming stays one fact in one place.
            'pick_up' => 'park:'.$call->parkQueueName(),
        ])->values()->all()]);
    }

    /**
     * Every live device this person has except the one asking.
     *
     * @return list<string> Voice identities
     */
    private function otherDevices(
        PhoneConnection $connection,
        string $userId,
        string $fromDeviceId,
    ): array {
        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        $out = [];
        foreach (PhoneDeviceRegistration::liveFor((string) $connection->id, [$userId]) as $device) {
            if ((string) $device->device_id === $fromDeviceId && $fromDeviceId !== '') {
                continue;
            }

            $out[] = ClientIdentity::of($user, (string) $device->device_id);
        }

        return $out;
    }

    /** GET /api/v1/phone/calls/{call}/recording */
    public function recording(PhoneCall $call): Response
    {
        $this->authorize('view', $call);

        $recordingSid = (string) ($call->recording_sid ?? '');
        if ($recordingSid === '') {
            abort(Status::HTTP_NOT_FOUND);
        }

        $connection = $call->connection;
        if ($connection === null) {
            abort(Status::HTTP_NOT_FOUND);
        }

        $bytes = $this->twilio->recordingMedia($connection, $recordingSid);

        return response($bytes, Status::HTTP_OK)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="recording.mp3"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PhoneCall>  $calls
     * @return array<string, string>
     */
    private function customerIdsByPhone($calls): array
    {
        $phones = [];
        foreach ($calls as $call) {
            $outside = $this->outsideNumber($call);
            if ($outside !== '') {
                $phones[] = $outside;
            }
        }

        if ($phones === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('phone', array_values(array_unique($phones)))
            ->pluck('id', 'phone')
            ->all();
    }

    /**
     * Names for the call legs that are colleagues rather than phone numbers.
     *
     * ── What the history was showing ────────────────────────────────
     *
     * A call to a teammate is addressed to a Voice identity, not a number,
     * and the webhook stores that identity in `to_e164` — so Recent calls
     * listed rows reading `01a020d0417c714ca5bf7fb3304070e1`. Correct data,
     * and unreadable: Jose asked what the strings were, which is the right
     * question to ask of a call log that names nobody.
     *
     * Resolved once for the page rather than per row, and only when a leg
     * really is an identity — a page of ordinary calls asks nothing extra.
     *
     * @param  \Illuminate\Support\Collection<int, PhoneCall>  $calls
     * Keyed by BOTH forms a person can be named in a call row: the hyphen-less
     * identity a client leg carries, and the plain id in `answered_by`. One
     * pass over the page, one lookup, and the caller does not have to know
     * which shape it holds.
     *
     * @return array<string, string> identity hex or user id => person's name
     */
    private function colleagueNames($calls): array
    {
        $identities = [];
        foreach ($calls as $call) {
            foreach ([$call->from_e164, $call->to_e164] as $leg) {
                $leg = (string) $leg;
                if ($leg !== '' && ! str_starts_with($leg, '+')) {
                    $identities[ClientIdentity::userPartOf($leg)] = true;
                }
            }

            if (is_string($call->answered_by) && $call->answered_by !== '') {
                $identities[$call->answered_by] = true;
            }
        }

        if ($identities === []) {
            return [];
        }

        $names = [];
        foreach (User::query()->get(['id', 'name']) as $user) {
            foreach ([ClientIdentity::of($user), (string) $user->id] as $key) {
                if (isset($identities[$key])) {
                    $names[$key] = (string) $user->name;
                }
            }
        }

        return $names;
    }

    private function outsideNumber(PhoneCall $call): string
    {
        return $call->direction === PhoneCall::DIRECTION_INBOUND
            ? (string) $call->from_e164
            : (string) $call->to_e164;
    }

    /**
     * @param  array<string, string>  $customerIds
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, string>  $colleagues
     */
    private function row(PhoneCall $call, array $customerIds = [], array $colleagues = []): array
    {
        $outside = $this->outsideNumber($call);

        return [
            'tag_id' => $call->tag_id,
            'tag' => $call->tag?->name,

            // Sent with the name so a call list can be read at a glance. A
            // colour set in Settings and invisible everywhere else would be
            // decoration, which is the shape this module keeps producing.
            'tag_color' => $call->tag?->color,
            'id' => $call->id,
            'number_id' => $call->number_id,
            'connection_id' => $call->connection_id,
            'twilio_sid' => $call->twilio_sid,
            'direction' => $call->direction,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,

            // Who the other end IS, when it is a colleague rather than a
            // number. Null for an ordinary call, so the screen falls back
            // to formatting the number exactly as it always did.
            'peer_name' => $colleagues[ClientIdentity::userPartOf($outside)] ?? null,
            'status' => $call->status,

            // WHICH of the people it rang picked up. An inbound call rings a
            // whole ring group, so "answered" named nobody and a customer
            // ringing back to continue a conversation could not be put through
            // to the person they had it with.
            'answered_by' => $call->answered_by,
            'answered_by_name' => $colleagues[(string) $call->answered_by] ?? null,

            // Why it failed, where Twilio said. A row that says "failed" and
            // nothing else is what turned four separate outbound defects into
            // four rounds of guessing.
            'error_code' => $call->error_code,
            'error_message' => $call->error_message,

            'duration_seconds' => $call->duration_seconds,
            'voicemail' => (bool) $call->voicemail,

            // Whether somebody has picked this voicemail up, so a box can show
            // what is still waiting rather than everything ever left.
            'dealt_at' => $call->dealt_at?->toIso8601String(),
            'recording_sid' => $call->recording_sid,
            'transcript' => $call->transcript,

            // The MESSAGE, not the call. Live transcription writes down the
            // whole conversation including our own greeting and menu, so a
            // voicemail screen fed from `transcript` reads the caller their
            // own IVR prompt back.
            'voicemail_transcript' => $call->voicemail_transcript,
            'customer_id' => $customerIds[$outside] ?? null,
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
