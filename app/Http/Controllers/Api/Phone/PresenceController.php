<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Jobs\PlaceQueueCallback;
use App\Jobs\RingSomebodyWhoJustBecameFree;
use App\Models\PhoneConnection;
use App\Models\PhonePresence;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\Response;

/**
 * Softphone presence on a Twilio connection — who is at the desk (spec §8).
 *
 * One row per (connection, user). Replaced in place; not a log.
 */
class PresenceController extends Controller
{
    private const NO_LINE = 'You have not been given a line on this phone account.';

    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/presence?connection_id= */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->hasLineOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $rows = PhonePresence::query()
            ->where('connection_id', $connection->id)
            // A deactivated or erased person keeps their row ("Removed account"
            // in Team, Jose 11-09); they are nobody's teammate any more.
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->with('user')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (PhonePresence $row) => [
                'user_id' => $row->user_id,
                'name' => $row->user?->name ?? '',

                // Not the stored string. A wrap-up that has run out is not a
                // state at all: routing already treats that person as
                // available, and reporting `wrap_up` here would put a screen
                // in front of a supervisor that contradicts who the phone
                // system is actually ringing.
                'state' => $row->isWrappingUp()
                    ? PhonePresence::STATE_WRAP_UP
                    : ($row->state === PhonePresence::STATE_WRAP_UP
                        ? PhonePresence::STATE_REGISTERED
                        : $row->state),
                'wrap_up_until' => $row->isWrappingUp()
                    ? $row->wrap_up_until?->toIso8601String()
                    : null,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** PUT /api/v1/phone/presence */
    /**
     * GET /api/v1/phone/presence/me — am I available, across every line?
     *
     * Presence is stored per connection because a call arrives on one. But
     * "I am not available" is a fact about the PERSON, and the app bar that
     * shows it has no connection in hand and no business learning about one.
     *
     * Reported as the strictest state held anywhere: somebody on Do Not
     * Disturb for one company and available for another is not available in
     * any sense the chrome can usefully draw, and showing green because one
     * row says so would be the reassuring half of a split answer.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = PhonePresence::query()
            ->where('user_id', $user->id)
            ->get();

        $states = $rows->pluck('state')->all();

        // A wrap-up that has run out is not a state at all. Reading the stored
        // string alone would leave somebody showing as busy for as long as the
        // row sat untouched, which is the stuck-icon bug wearing a new name.
        $wrapping = $rows->contains(fn (PhonePresence $row) => $row->isWrappingUp());

        return response()->json(['data' => [
            'has_line' => $rows->isNotEmpty(),
            'state' => match (true) {
                in_array(PhonePresence::STATE_DND, $states, true) => PhonePresence::STATE_DND,
                in_array(PhonePresence::STATE_ON_CALL, $states, true) => PhonePresence::STATE_ON_CALL,
                $wrapping => PhonePresence::STATE_WRAP_UP,
                default => PhonePresence::STATE_REGISTERED,
            },
        ]]);
    }

    /**
     * PUT /api/v1/phone/presence/me — set it everywhere at once.
     *
     * Somebody stepping away is stepping away from all of it. Setting this per
     * connection would mean a dispatcher covering three companies has to say
     * it three times and can leave one behind — and the one left behind is the
     * one that rings.
     *
     * Only rows that already exist are updated. A row is created when a desk
     * registers on a line; inventing one here would mark somebody present on a
     * connection they have never opened.
     */
    public function setMine(Request $request): JsonResponse
    {
        $input = $request->validate([
            'state' => ['required', Rule::in([
                PhonePresence::STATE_REGISTERED,
                PhonePresence::STATE_DND,
            ])],
        ]);

        $user = $request->user();

        $rows = PhonePresence::query()
            ->where('user_id', $user->id)

            // A live call is not a state anybody sets from a toggle, and
            // overwriting it would mark somebody free while they are talking.
            ->where('state', '!=', PhonePresence::STATE_ON_CALL);

        // Counted BEFORE the update, not taken from its return value.
        //
        // `update()` reports rows it CHANGED, so somebody setting themselves
        // available when they already are gets zero — a true number answering
        // a question nobody asked. What a caller wants to know is how many of
        // their lines this now applies to.
        $lines = (clone $rows)->count();

        // WHICH LINES, and read before the update for the same reason the
        // count is: afterwards there is no way to tell which rows this request
        // changed from the ones that already said it.
        $connectionIds = (clone $rows)
            ->pluck('connection_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->filter()
            ->values()
            ->all();

        $rows->update(['state' => $input['state']]);

        // "I AM BACK" HAPPENS HERE, AND ONLY HERE.
        //
        // This is the person's own toggle. The desk's endpoint below cannot
        // lift a standing Do Not Disturb by design — `storedState()` refuses
        // every state the software would like to write while somebody is away,
        // after Jose reported the same bug through two different transitions.
        //
        // So the two things that should happen when somebody becomes free were
        // wired to the one endpoint that can never see it happen. The queue
        // callback has been in that position since it was written: a caller
        // who pressed 1 and was promised a call back got one when a colleague
        // came off a CALL, and not when the person they were waiting for came
        // off Do Not Disturb — which is the commoner way of becoming free and
        // the one a person does deliberately.
        //
        // Per connection rather than once, because both jobs act on one line's
        // callers and one line's ringing calls.
        if ($input['state'] === PhonePresence::STATE_REGISTERED) {
            foreach ($connectionIds as $connectionId) {
                PlaceQueueCallback::dispatch(
                    (string) $this->tenant->idOrFail(),
                    $connectionId,
                    (string) $user->id,
                );

                RingSomebodyWhoJustBecameFree::dispatch(
                    (string) $this->tenant->idOrFail(),
                    $connectionId,
                    (string) $user->id,
                );
            }
        }

        return response()->json(['data' => [
            'state' => $input['state'],
            'lines' => $lines,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],
            'state' => ['required', Rule::in([
                PhonePresence::STATE_REGISTERED,
                PhonePresence::STATE_DND,
                PhonePresence::STATE_ON_CALL,

                // Accepted so a desk can put itself into wrap-up directly, and
                // so a client echoing back what it was last told is not a 422.
                PhonePresence::STATE_WRAP_UP,
            ])],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->hasLineOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $current = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $user->id)
            ->first();

        // AWAY ANYWHERE IS AWAY, and this row may not exist yet.
        //
        // Availability is stored per connection, but it is a decision a PERSON
        // makes, and the two kept coming apart. Measured on Jose's account:
        // two presence rows across four connections, both `dnd` — so a desk
        // starting on either of the other two called `updateOrCreate`, found
        // no row, and created one. A new row has no previous state, so the
        // guard below could not fire, and it was born taking calls while its
        // owner had said they were not.
        //
        // He reported it as the third variant of one bug: web on Do Not
        // Disturb, desktop showing available, and a call ringing him anyway.
        // His words: it should put my entire account on Do Not Disturb, not
        // just my current session.
        //
        // Asked of every connection rather than this one, so it covers the
        // creation as well as the change — and covers the third case too, a
        // row still marked `on_call` while the others are away, which
        // `setMine` skips deliberately and which used to come back available
        // the moment the call ended.
        $awayElsewhere = PhonePresence::query()
            ->where('user_id', $user->id)
            ->where('state', PhonePresence::STATE_DND)
            ->exists();

        $presence = PhonePresence::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'user_id' => $user->id,
            ],
            [
                'organization_id' => $this->tenant->idOrFail(),
                ...$this->storedState(
                    $connection,
                    $current,
                    (string) $input['state'],
                    $awayElsewhere,
                ),
            ],
        );

        // SOMEBODY IS FREE, AND SOMEBODY WAS PROMISED A CALL.
        //
        // This is the whole trigger for callbacks. A caller who pressed 1 in
        // the queue was told we would ring them "as soon as somebody is free",
        // and this request is the only moment in the system that means exactly
        // that — it is a person saying so about themselves.
        //
        // Queued rather than run here: placing a call is a round trip to
        // Twilio, and neither its latency nor its failures belong in the path
        // of the control that says "I am back". The job finds nothing and
        // stops when nothing is owed, which is almost every time.
        if ($presence->state === PhonePresence::STATE_REGISTERED) {
            PlaceQueueCallback::dispatch(
                (string) $this->tenant->idOrFail(),
                (string) $connection->id,
                (string) $user->id,
            );

            // AND A CALL THAT IS RINGING RIGHT NOW.
            //
            // The callback above is for somebody who was PROMISED a call and
            // hung up. This is the other half, which Jose asked for on
            // 2026-09-17: a call already ringing a colleague should start
            // ringing you too the moment you say you are back, rather than
            // waiting for it to overflow.
            //
            // Queued for the same reason, and it matters more here: the job
            // reads legs from Twilio and may redirect a live call, and neither
            // that latency nor its failures belong in the path of a control
            // whose only job is to say "I am available".
            RingSomebodyWhoJustBecameFree::dispatch(
                (string) $this->tenant->idOrFail(),
                (string) $connection->id,
                (string) $user->id,
            );
        }

        return response()->json([
            'data' => [
                'id' => $presence->id,
                'connection_id' => $presence->connection_id,
                'user_id' => $presence->user_id,
                'state' => $presence->state,
                'wrap_up_until' => $presence->wrap_up_until?->toIso8601String(),
                'updated_at' => $presence->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The state to actually store, whatever the desk asked for.
     *
     * Three transitions matter and they look identical on the wire, because
     * the desk sends "registered" for all of them:
     *
     *   on_call  → registered   the call just ended. If the company has asked
     *                           for wrap-up time, this becomes a wrap-up with
     *                           a deadline instead — the moment the feature
     *                           exists for.
     *   wrap_up  → registered   somebody pressed "I'm ready". That is a person
     *                           saying they are done, and it must win, or the
     *                           button does nothing.
     *   dnd      → registered   a DESK COMING UP, which is not a person saying
     *                           anything. It is ignored: see below.
     *
     * ── Why a desk registering cannot clear Do Not Disturb ───────
     *
     * PhoneCallService::start() ends by PUTting `registered` for the
     * connection it just brought up. MEASURED rather than assumed, because the
     * first version of this comment named three triggers and two of them were
     * wrong: `start()` opens with `if (_started || _starting) return;` and the
     * service is a singleton, so it runs ONCE per app process. It is reached by
     *
     *   - the shell mounting, which is an app launch or a sign-in;
     *   - `restart()`, the "Try again" button on the desk-down banner;
     *   - `stop()` then a new shell, which is signing out and back in.
     *
     * NOT by switching companies, and NOT by a socket dropping and recovering
     * — nothing calls `restart()` on its own. Whichever of these it is, none is
     * somebody saying they are ready to take calls; they are the software
     * saying a device is attached.
     *
     * Jose set himself to Not taking calls at 19:39 and took a call at 19:44.
     * Measured on production rather than guessed: his EzBizi row said `dnd`
     * and his A-1 Chimney row said `registered`, written five minutes later —
     * the desk coming up on the other company, silently cancelling the
     * decision. `setMine` had done its job and set every line to `dnd`; this
     * endpoint undid one of them.
     *
     * So the rule is about WHO is speaking rather than what was sent. Only the
     * availability toggle takes somebody out of Do Not Disturb, because only
     * that is a person choosing. It goes through `setMine`, which never
     * reaches here.
     *
     * Told apart by the state ALREADY STORED rather than by anything the
     * client sends. A flag on the request would be a flag a stale client omits,
     * and the failure would be silent: no wrap-up, and nobody able to say why.
     *
     * @return array<string, mixed>
     */
    private function storedState(
        PhoneConnection $connection,
        ?PhonePresence $current,
        string $wanted,
        bool $awayElsewhere = false,
    ): array {
        // FIRST, and for EVERY state rather than just `registered`.
        //
        // The first version of this guard only caught `registered`, and Jose
        // reported the same bug again two days later. Measured on the call he
        // reported — Twilio's child legs for CAef3d3d6e, which name who the
        // dial actually rang — he was one of six people it named, so he was
        // reachable at 19:39:33 while his rows read `dnd` fourteen seconds
        // later.
        //
        // The route was `on_call`. Do Not Disturb stops calls arriving; it
        // does not stop you PLACING one. So: set yourself away, ring a
        // customer, and the desk writes `on_call` over the `dnd` — which
        // destroys the evidence that a decision was ever made. When the call
        // ends `_releasePresence()` writes `registered`, the guard looks for a
        // stored `dnd` that is no longer there, and the decision is gone with
        // nobody having touched the button.
        //
        // Fixing `registered` alone was fixing the instance rather than the
        // class. This endpoint is only ever called by the desk — the person's
        // own toggle goes through `setMine` and never arrives here — so the
        // rule that actually holds is: THE DESK CANNOT CHANGE A STANDING
        // DECISION. While somebody is away, every state the software would
        // like to write is a no-op.
        //
        // Nothing is lost by refusing `on_call` here. It exists to keep a ring
        // group from dialling somebody mid-call, and `dnd` already excludes
        // them from exactly that (PhonePresence::scopeAvailable). The "on a
        // call" lamp on the Dial picker reads live PhoneCall rows rather than
        // this column, so it is unaffected.
        // The rule, stated once, for this row and for every other.
        //
        // Twice now I have written a guard naming the states a desk may not
        // write, and twice the next report came through a state the guard did
        // not name — first `on_call`, then a row that did not exist yet. Both
        // times the mistake was the same: describing the transition instead of
        // the person. So this asks the only question that decides it — is this
        // person away, anywhere — and the desk cannot answer yes to a call
        // while they are.
        if ($current?->state === PhonePresence::STATE_DND || $awayElsewhere) {
            return [
                'state' => PhonePresence::STATE_DND,
                'wrap_up_until' => null,
            ];
        }

        $seconds = (int) $connection->wrap_up_seconds;

        $justFinished = $wanted === PhonePresence::STATE_REGISTERED
            && $current?->state === PhonePresence::STATE_ON_CALL
            && $seconds > 0;

        if ($justFinished) {
            return [
                'state' => PhonePresence::STATE_WRAP_UP,
                'wrap_up_until' => now()->addSeconds($seconds),
            ];
        }

        // Any other transition clears the deadline. Leaving a stale one behind
        // would put somebody back into wrap-up the next time they were marked
        // available, from a call that ended an hour ago.
        return ['state' => $wanted, 'wrap_up_until' => null];
    }

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
