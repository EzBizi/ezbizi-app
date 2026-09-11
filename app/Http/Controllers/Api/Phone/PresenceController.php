<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhonePresence;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $rows->update(['state' => $input['state']]);

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

        $presence = PhonePresence::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'user_id' => $user->id,
            ],
            [
                'organization_id' => $this->tenant->idOrFail(),
                ...$this->storedState($connection, $current, (string) $input['state']),
            ],
        );

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
    ): array {
        // FIRST, because a desk registering must not reach any rule that would
        // hand it back "registered".
        if ($wanted === PhonePresence::STATE_REGISTERED
            && $current?->state === PhonePresence::STATE_DND) {
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

    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
