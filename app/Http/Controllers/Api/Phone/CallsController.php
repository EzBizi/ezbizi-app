<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\DialPlan;
use App\Support\Phone\NumberNames;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;
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
            // Left in THIS box by a group or a person (voicemail_box_id), or
            // in no particular box on a line that resolves to it.
            $query->where('voicemail', true)->where(fn ($in) => $in
                ->where('voicemail_box_id', $boxId)
                ->orWhere(fn ($line) => $line->whereNull('voicemail_box_id')->whereIn(
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
            )));
        }

        // Voicemail tiles (Mia, 2026-10-02): To deal with, Dealt with, No
        // transcript, All. The screen asks for 50 and shows no total, so a
        // count taken there would stop at 50 and call it everything. Counted
        // here over every message the box (or All voicemails) holds, BEFORE
        // the tile's own filter, so picking a tile does not zero the others.
        $tiles = null;
        if ($request->boolean('voicemail_only') || $boxId !== '') {
            $tiles = [
                'todo' => (clone $query)->reorder()->whereNull('dealt_at')->count(),
                'dealt' => (clone $query)->reorder()->whereNotNull('dealt_at')->count(),
                'no_transcript' => $this->withoutVoicemailTranscript((clone $query)->reorder())->count(),
                'all' => (clone $query)->reorder()->count(),
            ];
        }

        // The tile filters. `dealt` is the dealt_at column read as yes/no;
        // `transcript=0` is the MESSAGE transcript missing, the same field the
        // Voicemail screen prints, not the live one Records shows.
        $dealt = (string) $request->query('dealt', '');
        if ($dealt === '0') {
            $query->whereNull('dealt_at');
        } elseif ($dealt === '1') {
            $query->whereNotNull('dealt_at');
        }

        if ((string) $request->query('transcript', '') === '0') {
            $this->withoutVoicemailTranscript($query);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $query->limit(min($perPage, 200));
        }

        $calls = $query->get();

        $customerIds = $this->customerIdsByPhone($calls);
        $customerNames = $this->customerNamesById(array_values($customerIds));
        $colleagues = $this->colleagueNames($calls);

        // Jose, 2026-10-09: most rows were a bare number. The same lookup
        // Messages uses: a customer by any spelling of the phone, a lead, a
        // colleague.
        $callers = app(NumberNames::class)->for($calls->map(fn (PhoneCall $call) => $this->outsideNumber($call)));

        $rows = $calls
            ->map(fn (PhoneCall $call) => $this->row($call, $customerIds, $colleagues, $customerNames, $callers))
            ->all();

        return response()->json($tiles === null
            ? ['data' => $rows]
            : ['data' => $rows, 'meta' => ['tiles' => $tiles]]);
    }

    /**
     * A voicemail with nothing written down: no text, or only whitespace —
     * the screen shows both as "No transcript", so the tile counts both.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function withoutVoicemailTranscript($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNull('voicemail_transcript')
            ->orWhereRaw("TRIM(voicemail_transcript) = ''"));
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
     * POST /api/v1/phone/calls/silence
     *
     * I have dealt with this call on THIS device. Stop my other phones.
     *
     * ── What Jose reported ──────────────────────────────────────────
     *
     * 2026-09-17: *"all 3 ring, i decline on desktop, it should update that it
     * was declined on the others and stop ringing"*. Same for answering.
     *
     * ── Why Twilio does not do this by itself ───────────────────────
     *
     * A `<Dial>` makes one child leg per `<Client>` noun, and one person with
     * three devices is three nouns. ANSWERING ends the siblings, because a
     * Dial connects the first to answer and hangs up the rest. DECLINING does
     * not, and should not: a leg refused by one person must leave the call
     * ringing for the colleagues it is also offered to. Twilio cannot tell
     * "this person is not taking it" from "this person's other phone already
     * has it", because it does not know two identities are one human.
     *
     * We do. So this ends the legs belonging to the person asking, except the
     * device that asked — and touches nothing belonging to anybody else.
     *
     * ── Why the device that asked is spared rather than trusted ─────
     *
     * A device that answered is mid-conversation and ending its leg would drop
     * the call it just took. A device that declined has already ended its own
     * leg, so sparing it costs nothing. One rule covers both, which is why
     * accepting and declining call the same endpoint: two endpoints would be
     * two chances to end the wrong leg.
     *
     * ── Why the call is found rather than named ─────────────────────
     *
     * The client holds the SID of its OWN leg, not the parent's, and the
     * parent is what the other legs hang off. So this looks for calls ringing
     * on lines this person can be rung on — a small set, and empty most of the
     * time — rather than trusting a sid the caller could not know.
     */
    public function silence(Request $request): JsonResponse
    {
        $input = $request->validate([
            // Required, unlike on transfer(). There, a missing device id means
            // the asking device also gets rung, which is visible and harmless.
            // Here it would mean ending the leg of the device that just
            // answered — silently hanging up on a customer.
            'from_device_id' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $from = (string) $input['from_device_id'];

        $connectionIds = $this->access->readable($user)
            ->pluck('connection_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        if ($connectionIds === []) {
            return response()->json(['data' => ['ended' => 0]]);
        }

        // RINGING, and recently. `status` is moved by Twilio's callbacks and a
        // call whose last one never arrives stays ringing in this table for
        // ever — the same ceiling the call-flow chart applies, and for the same
        // reason: without it this would hunt legs of calls that ended days ago
        // on every decline.
        $ringing = PhoneCall::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('status', PhoneCall::STATUS_RINGING)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->get();

        $ended = 0;

        foreach ($ringing as $call) {
            $connection = $call->connection;
            if ($connection === null) {
                continue;
            }

            try {
                $legs = $this->twilio->childLegs($connection, (string) $call->twilio_sid);
            } catch (\Throwable) {
                // Twilio unreachable. Nothing to report as broken: the other
                // phones keep ringing, which is the behaviour this improves on
                // rather than the behaviour it depends on.
                continue;
            }

            foreach ($legs as $leg) {
                $to = (string) ($leg['to'] ?? '');
                if (! str_starts_with($to, 'client:')) {
                    continue;
                }

                $identity = substr($to, strlen('client:'));

                // SOMEBODY ELSE'S PHONE IS NOT MINE TO HANG UP. This is the
                // clause the whole endpoint turns on: a ring group offers one
                // call to several people, and ending a colleague's leg because
                // I declined would take the call away from the person about to
                // answer it.
                if (! ClientIdentity::belongsTo($identity, $user)) {
                    continue;
                }

                // The device that asked. Answered, so ending it would drop the
                // conversation; or declined, so its leg is already gone.
                if (ClientIdentity::devicePartOf($identity) === $from) {
                    continue;
                }

                // Still ringing. A leg that already ended does not need
                // ending, and asking Twilio to complete a completed call is a
                // request that can only fail.
                if (! in_array((string) ($leg['status'] ?? ''), ['queued', 'initiated', 'ringing'], true)) {
                    continue;
                }

                try {
                    $this->twilio->endCall($connection, (string) $leg['sid']);
                    $ended++;
                } catch (\Throwable) {
                    // One phone that would not stop is not a reason to leave
                    // the others ringing.
                }
            }
        }

        return response()->json(['data' => ['ended' => $ended]]);
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
     * GET /api/v1/phone/calls/ringing
     *
     * Calls ringing somewhere right now, on the lines this person can see.
     *
     * ── What this is for ─────────────────────────────────────────────
     *
     * Call pickup, asked for by name. A call is ringing a group you are not
     * in — Sales rings, you are in Support, everybody in Sales is out — and
     * the only thing you can do today is watch it go to voicemail.
     *
     * Scoped by grant rather than by ring group deliberately: whether you may
     * ANSWER a line is the grant, and whether a line rings YOU is the group.
     * Pickup exists precisely for the calls that are not ringing you, so
     * filtering by the group would leave the list empty in the one case it is
     * for.
     */
    /**
     * GET /api/v1/phone/caller — who is this number, if we know them.
     *
     * ── Why the pop-up cannot use what it already has ───────────────
     *
     * The softphone learns a caller from the Voice SDK, which gives it a
     * number and nothing else. Jose asked for the incoming pop-up to show a
     * NAME when the caller is in the database and the number underneath it, so
     * something has to turn one into the other — and the number is all the
     * client holds.
     *
     * ── Why it is its own endpoint ──────────────────────────────────
     *
     * The pop-up is drawn by the app shell, on top of whatever module is open,
     * and a shell that had to load the Communication screens to name a caller
     * would be the tail wagging the dog. This asks one question and answers
     * it.
     *
     * ── What it deliberately does not return ────────────────────────
     *
     * Nothing but a name and the id behind it. A ringing phone needs "who is
     * this"; the address, the balance and the job history belong on the screen
     * somebody opens afterwards, and a pop-up that carried them would be
     * putting a customer record on screen for anybody who can hear a phone.
     */
    public function caller(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $input = $request->validate([
            'e164' => ['required', 'string', 'max:32'],
        ]);

        // Matched on the stored column as it stands, the same way call records
        // resolve a customer — see customerIdsByPhone(). A looser match would
        // find more people and would also find the WRONG people, and being
        // told the wrong name on an incoming call is worse than being told
        // none.
        $customer = Customer::query()
            ->where('phone', $input['e164'])
            ->first(['id', 'name', 'postal_code']);

        return response()->json(['data' => [
            'e164' => $input['e164'],
            'name' => $customer?->name,
            'customer_id' => $customer?->id,

            // For the Dial pad's Availability panel, which opens on the
            // caller's own zip when they are on file (Toula's suggestion).
            'postal_code' => $customer?->postal_code,
        ]]);
    }

    public function ringing(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $numberIds = $this->access->readable($request->user())->pluck('id')->all();

        // A ceiling as well as a floor. `status` is moved by Twilio's status
        // webhook, and a call whose final callback never arrives stays
        // `ringing` in this table for ever — offering somebody a Pick up
        // button for a conversation that ended last Tuesday. Nothing rings for
        // two minutes, so anything older than that is a row we never heard the
        // end of rather than a call.
        $calls = PhoneCall::query()
            ->where('status', PhoneCall::STATUS_RINGING)
            ->whereIn('number_id', $numberIds)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->whereNull('parked_at')
            ->orderBy('created_at')
            ->limit(10)
            ->get();

        return response()->json(['data' => $calls->map(fn (PhoneCall $call) => [
            'id' => $call->id,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'created_at' => optional($call->created_at)->toIso8601String(),
        ])->values()->all()]);
    }

    /**
     * POST /api/v1/phone/calls/{call}/pickup
     *
     * Take a call that is ringing somebody else.
     *
     * ── Why this is the transfer TwiML ───────────────────────────────
     *
     * Because it is the same move: point the CUSTOMER's leg at a set of
     * devices. Transfer aims it at the devices of the person already on the
     * call; pickup aims it at the devices of the person asking. Rendering a
     * second document for that would be a second way for the recording
     * callback and the dial action to drift.
     *
     * ── Why only while it is RINGING ─────────────────────────────────
     *
     * An in-progress call has somebody talking on it. Redirecting that is not
     * a pickup, it is taking a customer away from a colleague mid-sentence —
     * and from the caller's side the two are indistinguishable, which is
     * exactly why the guard is on the status rather than on the button.
     */
    public function pickUp(Request $request, PhoneCall $call): JsonResponse
    {
        $this->authorize('view', $call);

        if ($call->status !== PhoneCall::STATUS_RINGING) {
            return response()->json([
                'message' => 'That call is no longer ringing.',
            ], Status::HTTP_CONFLICT);
        }

        $connection = $call->connection;
        $number = $call->number_id === null ? null : PhoneNumber::query()->find($call->number_id);

        if ($connection === null || $number === null) {
            return response()->json([
                'message' => 'That call is not on a line EzBizi can move it from.',
            ], Status::HTTP_CONFLICT);
        }

        // Every live device this person has. '' rather than a device id: they
        // are not on the call yet, so there is no leg of theirs to exclude and
        // whichever of their phones they are near should ring.
        $targets = $this->otherDevices($connection, (string) $request->user()->id, '');

        if ($targets === []) {
            return response()->json([
                'message' => 'No device of yours is signed in to take it.',
            ], Status::HTTP_CONFLICT);
        }

        // Refused BEFORE the redirect rather than after. transfer() renders a
        // document that apologises to the caller and takes a message when it
        // is handed nothing to ring — correct for a transfer that fails, and
        // wrong here: it would end a call that is still perfectly alive and
        // still ringing somebody who might yet answer.

        try {
            $this->twilio->redirectCall(
                $connection,
                (string) $call->twilio_sid,
                $this->renderer->transfer($number, $targets),
            );
        } catch (\Throwable) {
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
     * GET /api/v1/phone/call-flow/live
     *
     * What is happening on this line right now, for the call-flow chart.
     *
     * ── What is answerable and what is not ───────────────────────────
     *
     * Only two things, and the shape says so rather than padding it out:
     *
     *   `queues` — EXACT, and asked of Twilio, which is where the callers
     *   actually are. Keyed by RING GROUP id, matching the `ref` on the queue
     *   nodes `TwimlRenderer::graph()` emits, so a count joins to a box
     *   without anybody re-deriving node ids. A group that has never held
     *   anybody has no queue on the account, and a missing key means zero.
     *
     *   `line` — calls ringing or connected on this number, from our own
     *   rows. One number for the whole picture.
     *
     * There is deliberately NO per-node count for the menu or a ring group. A
     * caller listening to a menu, a caller ringing a team and a caller holding
     * are all `status = ringing` on the row; nothing records which step a call
     * is at. A column that did would go stale exactly the way `status`
     * already does when a final callback never arrives — the pickup list
     * needed a two-minute ceiling for that — and a tile reading "2 calls here"
     * about a call that ended on Tuesday is worse than a tile reading nothing.
     */
    public function live(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $input = $request->validate([
            'line' => ['required', 'string', 'max:191'],
        ]);

        $number = $this->access->readable($request->user())
            ->firstWhere('id', $input['line']);

        if ($number === null) {
            return response()->json(['message' => 'No such line.'], Status::HTTP_NOT_FOUND);
        }

        $connection = $number->connection;

        // Same ceiling as the ringing list, and for the same reason: `status`
        // is moved by Twilio's callbacks, and a call whose final one never
        // arrives stays `ringing` here for ever. Without this the chart shows
        // a caller who hung up last Tuesday, permanently.
        $line = PhoneCall::query()
            ->where('number_id', $number->id)
            ->whereIn('status', [PhoneCall::STATUS_RINGING, PhoneCall::STATUS_IN_PROGRESS])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        $queues = [];

        // "NOBODY IS WAITING" AND "WE COULD NOT ASK" ARE DIFFERENT FACTS.
        //
        // This returned an empty map on failure, which a screen renders as a
        // row of confident zeroes — the chart would say the queues are empty
        // at the exact moment it has no idea. UI/UX asked for the difference
        // before drawing anything, and they were right to: an empty map is
        // indistinguishable from a quiet afternoon.
        //
        // So the flag is the answer, not the emptiness. False means show
        // nothing on the queue tiles; the rest of the chart is unaffected,
        // because the graph itself does not come from Twilio.
        $known = false;

        if ($connection !== null) {
            try {
                foreach ($this->twilio->queueSizes($connection) as $name => $size) {
                    // `ezbizi-<ringGroupId>` — see PhoneRingGroup::queueName().
                    // A park queue is a different feature on the same account
                    // and must not be reported as people holding for a team;
                    // anything else is not ours at all.
                    if (! str_starts_with($name, 'ezbizi-') || str_starts_with($name, 'ezbizi-park-')) {
                        continue;
                    }

                    $queues[substr($name, strlen('ezbizi-'))] = [
                        'waiting' => $size['waiting'],
                        'average_wait_seconds' => $size['average_wait'],
                    ];
                }

                $known = true;
            } catch (\Throwable) {
                $queues = [];
            }
        }

        return response()->json(['data' => [
            'id' => (string) $number->id,

            // Ringing or connected on this line right now.
            'on_line' => $line,

            // Keyed by RING GROUP id, which is the `ref` on the queue nodes
            // `TwimlRenderer::graph()` emits — `counts[node.ref]` is the whole
            // join. Deliberately not keyed by node id: the node ids depend on
            // the menu (`queue-answer` versus `queue-key-3`), so producing
            // them here would mean rebuilding the entire graph — flow, groups
            // and availability — on every ten-second poll, to learn a number
            // that does not depend on any of it.
            'queues' => (object) $queues,

            // Whether Twilio answered at all. See above: absent-and-known is
            // zero, absent-and-unknown is nothing to draw.
            'queues_known' => $known,
        ]]);
    }

    /**
     * POST /api/v1/phone/page
     *
     * Speak to a whole team's handsets at once.
     *
     * ── What a page is, and what it is not ───────────────────────────
     *
     * One person talks, everybody in the group hears it, nobody answers
     * anything. It is the intercom, not a conference call: the listeners are
     * muted, and the announcement ends when the speaker hangs up.
     *
     * ── Why this returns a destination rather than placing the call ──
     *
     * The speaker's own audio has to come from their desk, and the desk is
     * what owns the microphone. So this connects the LISTENERS and hands back
     * the destination for the speaker to dial — the same shape as picking up a
     * parked call. A server that tried to originate the speaker's leg too
     * would be calling somebody to tell them to start talking.
     *
     * ── Why a page cannot reach somebody on a call ───────────────────
     *
     * Because it would be played into their ear mid-conversation, and the
     * customer would hear half of it. Anybody on a call is skipped and named
     * in the response, so the person paging knows who did not get it rather
     * than assuming the room heard them.
     */
    public function page(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $input = $request->validate([
            'ring_group_id' => ['required', 'string', 'max:191'],
        ]);

        $user = $request->user();

        $group = PhoneRingGroup::query()->find($input['ring_group_id']);
        if ($group === null) {
            return response()->json(['message' => 'No such group.'], Status::HTTP_NOT_FOUND);
        }

        $connection = $group->connection;
        if ($connection === null || ! $this->access->hasLineOn($user, $connection)) {
            return response()->json([
                'message' => 'That group is not on a line you can use.',
            ], Status::HTTP_FORBIDDEN);
        }

        $line = $this->access->readable($user)
            ->first(fn (PhoneNumber $number) => $number->connection_id === $connection->id);

        if ($line === null) {
            return response()->json([
                'message' => 'You have no line on that account to page from.',
            ], Status::HTTP_CONFLICT);
        }

        // Named after the group and the moment. Two pages to one team at the
        // same second would otherwise be one conference with two people
        // talking over each other, which is the failure this is for avoiding.
        $conference = 'ezbizi-page-'.$group->id.'-'.now()->timestamp;

        $reached = 0;
        $skipped = [];

        // `roster()` rather than `reachableUserIds()`: both know who is
        // unavailable, and only the roster says WHY. Somebody who missed an
        // announcement because they were mid-call is the thing the person
        // paging most needs to be told — silently dropping them leaves them
        // assuming the room heard it.
        foreach (DialPlan::roster($group, $connection) as $row) {
            $memberId = (string) $row['user_id'];

            // Never yourself: paging a room you are standing in plays your own
            // voice back at you a half-second late, which is unusable.
            if ($memberId === (string) $user->id) {
                continue;
            }

            // On a call, or not taking them. Do Not Disturb is skipped as well
            // as on_call, deliberately — a page is a disturbance, and somebody
            // who has said they are not available has said it about noise
            // coming out of their phone as much as about calls.
            if (! $row['reachable']) {
                $skipped[] = ['name' => $row['name'], 'state' => $row['state']];

                continue;
            }

            foreach (PhoneDeviceRegistration::liveFor((string) $connection->id, [$memberId]) as $device) {
                try {
                    $this->twilio->createCall(
                        $connection,
                        (string) $line->e164,
                        'client:'.ClientIdentity::of(
                            User::query()->find($memberId),
                            (string) $device->device_id,
                        ),
                        $this->renderer->pageListener($conference),
                    );

                    $reached++;
                } catch (\Throwable) {
                    // One handset that could not be reached is not a reason to
                    // abandon the announcement to everybody else.
                }
            }
        }

        if ($reached === 0) {
            return response()->json([
                'message' => 'Nobody in that group has a device to page right now.',
            ], Status::HTTP_CONFLICT);
        }

        return response()->json(['data' => [
            'devices' => $reached,
            'skipped' => $skipped,

            // What the desk dials to start speaking.
            'speak_to' => 'page:'.$conference,
        ]]);
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
    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function customerNamesById(array $ids): array
    {
        return $ids === []
            ? []
            : Customer::query()->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

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
     *
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
    private function row(PhoneCall $call, array $customerIds = [], array $colleagues = [], array $customerNames = [], array $callers = []): array
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

            // The name beside the id, so a history row can say who rather
            // than a number (UI/UX's Dial redesign, 2026-09-30).
            'customer_name' => $customerNames[$customerIds[$outside] ?? ''] ?? null,

            // Who has the number when the exact match above found nobody:
            // kind is customer, lead or colleague (NumberNames).
            'caller_name' => $callers[$outside]['name'] ?? null,
            'caller_kind' => $callers[$outside]['kind'] ?? null,

            // What they pressed in the menu, and any extension they typed.
            'menu_key' => $call->menu_key,
            'extension_dialed' => $call->extension_dialed,
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
