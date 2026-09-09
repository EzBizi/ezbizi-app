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
                'state' => $row->state,
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
                ...$this->wrapUp($connection, $current, (string) $input['state']),
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
     * The state to actually store, once wrap-up has had its say.
     *
     * Two transitions matter and they look identical on the wire, because the
     * desk sends "registered" for both:
     *
     *   on_call  → registered   the call just ended. If the company has asked
     *                           for wrap-up time, this becomes a wrap-up with
     *                           a deadline instead — the moment the feature
     *                           exists for.
     *   wrap_up  → registered   somebody pressed "I'm ready". That is a person
     *                           saying they are done, and it must win, or the
     *                           button does nothing.
     *
     * Told apart by the state ALREADY STORED rather than by anything the
     * client sends. A flag on the request would be a flag a stale client omits,
     * and the failure would be silent: no wrap-up, and nobody able to say why.
     *
     * @return array<string, mixed>
     */
    private function wrapUp(
        PhoneConnection $connection,
        ?PhonePresence $current,
        string $wanted,
    ): array {
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
