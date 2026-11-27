<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short numbers that reach one person.
 *
 * Twilio has no concept of an extension. To Twilio these are Client
 * identities; the digits are ours, and resolving them before we dial is the
 * whole of the feature.
 */
class ExtensionsController extends Controller
{
    use ResolvesPhoneConnections;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/phone/extensions
     *
     * Everyone who could hold an extension, and the one they hold.
     *
     * People without one are listed too. A screen that shows only assigned
     * extensions makes "who has not got one yet" the question you cannot
     * answer, which is the question somebody setting this up is actually
     * asking.
     */
    /**
     * GET /api/v1/phone/extensions/directory
     *
     * Every extension on this account, and what it reaches.
     *
     * ── Why this is not `index()` ───────────────────────────────────
     *
     * They read one table and answer two different questions. `index()` is
     * PEOPLE-shaped: every active person, with the extension they hold — which
     * is what the Users tab needs, and it keys by `user_id`. An extension that
     * points at a TEAM has no user, so every one of them would collapse into a
     * single null key and vanish.
     *
     * This is EXTENSION-shaped: one row per set of digits, with whatever it
     * reaches. The Extensions screen is a list of numbers somebody has
     * published to customers, and that is the thing it has to be complete
     * about.
     *
     * ── What is sent with it ────────────────────────────────────────
     *
     * The assignable targets, for the same reason `index()` sends the lines: a
     * picker offering a team from another phone account produces an extension
     * that rings nothing, and the two lists would have to be reconciled
     * anyway.
     */
    public function directory(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $connections = $this->connectionsInReach($request->user());

        if ($connections->isEmpty()) {
            return response()->json([
                'data' => [],
                'connection_id' => null,
                'people' => [],
                'ring_groups' => [],
            ]);
        }

        $connection = $connections->first();

        $rows = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->whereNotNull('extension')
            ->where('extension', '!=', '')
            ->with(['user', 'ringGroup'])
            ->orderBy('extension')
            ->get()
            ->map(fn (PhoneExtension $row) => [
                'id' => $row->id,
                'extension' => $row->extension,
                'target_kind' => $row->targetKind(),
                'target_id' => $row->user_id ?? $row->ring_group_id ?? $row->flow_id,

                // Null when the thing it pointed at is gone. A team can be
                // deleted while its digits stay published to customers, so the
                // row survives pointing at nothing and the screen shows it as
                // needing a destination rather than hiding it.
                'target_name' => $row->user?->name ?? $row->ringGroup?->name,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'connection_id' => $connection->id,
            'people' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
            'ring_groups' => PhoneRingGroup::query()
                ->where('connection_id', $connection->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (PhoneRingGroup $group) => ['id' => $group->id, 'name' => $group->name])
                ->all(),
        ]);
    }

    /**
     * POST /api/v1/phone/extensions/directory
     *
     * Give a set of digits to a person or a team.
     *
     * ── Why this is not `update()` either ───────────────────────────
     *
     * `update()` is keyed on a PERSON — it is "set this person's extension",
     * which is the right shape for the Users tab and cannot express a team.
     * This is keyed on the DIGITS, which is the right shape for a screen whose
     * subject is the extension.
     *
     * The uniqueness rule is shared and is the one thing that must not be
     * duplicated: "call 0101" has to mean one thing, whether the row was made
     * here or there.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'extension' => ['required', 'string', 'regex:'.PhoneExtension::PATTERN],
            'target_kind' => ['required', Rule::in([
                PhoneExtension::TARGET_USER,
                PhoneExtension::TARGET_RING_GROUP,
            ])],
            'target_id' => ['required', 'uuid'],
        ]);

        $connection = $this->connectionsInReach($request->user())
            ->firstWhere('id', $input['connection_id']);

        if ($connection === null) {
            return response()->json([
                'message' => 'That phone account is not one you can use.',
            ], Response::HTTP_FORBIDDEN);
        }

        // On the CONNECTION, the same ability `update()` uses below: giving
        // out an extension is editing how this phone account answers. `create`
        // on PhoneNumber is the buy-a-number ability and takes a scope.
        $this->authorize('update', $connection);

        $user = null;
        $group = null;

        if ($input['target_kind'] === PhoneExtension::TARGET_RING_GROUP) {
            // On THIS account. A team from another one expands to Client
            // identities this line can never reach, so the extension would
            // ring nobody and look perfectly configured.
            $group = PhoneRingGroup::query()
                ->where('connection_id', $connection->id)
                ->find($input['target_id']);

            if ($group === null) {
                throw ValidationException::withMessages([
                    'target_id' => 'That team is not on this phone account.',
                ]);
            }
        } else {
            $user = User::query()->where('is_active', true)->find($input['target_id']);

            if ($user === null) {
                throw ValidationException::withMessages([
                    'target_id' => 'That person was not found.',
                ]);
            }
        }

        $taken = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->where('extension', $input['extension'])
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages([
                'extension' => $this->takenBy($taken, (string) $input['extension']),
            ]);
        }

        // One extension per person, which the unique index already enforces —
        // said here so it arrives as a sentence rather than a 500.
        if ($user !== null) {
            $already = PhoneExtension::query()
                ->where('connection_id', $connection->id)
                ->where('user_id', $user->id)
                ->first();

            if ($already !== null) {
                throw ValidationException::withMessages([
                    'target_id' => $user->name.' already has extension '.$already->extension.'.',
                ]);
            }
        }

        $row = PhoneExtension::query()->create([
            'organization_id' => $this->tenant->idOrFail(),
            'connection_id' => $connection->id,
            'extension' => $input['extension'],
            'user_id' => $user?->id,
            'ring_group_id' => $group?->id,
        ]);

        return response()->json(['data' => [
            'id' => $row->id,
            'extension' => $row->extension,
            'target_kind' => $row->targetKind(),
            'target_id' => $user?->id ?? $group?->id,
            'target_name' => $user?->name ?? $group?->name,
        ]], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/phone/extensions/{extension}
     *
     * Take a set of digits out of service.
     *
     * Deleted rather than blanked: an extension row with no digits is not a
     * thing anybody wants to look at, and `update()` already deletes for the
     * same reason when somebody clears the field.
     */
    public function destroy(Request $request, string $extension): JsonResponse
    {
        $row = PhoneExtension::query()->find($extension);

        if ($row === null) {
            return response()->json(['message' => 'That extension is already gone.'], Response::HTTP_NOT_FOUND);
        }

        $reachable = $this->connectionsInReach($request->user())
            ->contains(fn ($connection) => (string) $connection->id === (string) $row->connection_id);

        if (! $reachable) {
            return response()->json([
                'message' => 'That extension is not on a phone account you can use.',
            ], Response::HTTP_FORBIDDEN);
        }

        $connection = PhoneConnection::query()->find($row->connection_id);
        if ($connection !== null) {
            $this->authorize('update', $connection);
        }

        $row->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Who holds these digits already, said so somebody can go and find them. */
    private function takenBy(PhoneExtension $taken, string $digits): string
    {
        return match ($taken->targetKind()) {
            PhoneExtension::TARGET_USER => ($taken->user?->name ?? 'Somebody')
                .' already has extension '.$digits.'.',
            PhoneExtension::TARGET_RING_GROUP => ($taken->ringGroup?->name ?? 'A team')
                .' already has extension '.$digits.'.',
            default => 'Extension '.$digits.' is already taken.',
        };
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);
        $this->mayOpenUsersOrSettings($request);

        $connections = $this->connectionsInReach($request->user());

        if ($connections->isEmpty()) {
            return response()->json([
                'data' => [],
                'connection_id' => null,
                'numbers' => [],
            ]);
        }

        // The viewed company's own account when it has one (EverDoors, 2026-11-11).
        $inView = \App\Support\Scoping\ViewScope::forUser($request->user())->companyIdsInView() ?? [];
        $connection = $connections->first(fn (PhoneConnection $c) => $c->scope_type === PhoneConnection::SCOPE_COMPANY
            && in_array($c->scope_id, $inView, true)) ?? $connections->first();

        // Anybody still without one gets the next free extension before the list is read.
        \App\Support\Phone\ExtensionAssigner::assignMissing($request->user()->organization);

        $held = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->get()
            ->keyBy('user_id');

        $rows = \App\Support\Phone\PhoneScope::people(User::query(), $request->user())
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'extension' => $held[$user->id]->extension ?? null,

                // Which line this person calls out from. Null means they fall
                // back to whichever line the Call tab has selected, which is
                // how every person behaved before this existed.
                'number_id' => $held[$user->id]->number_id ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'connection_id' => $connection->id,

            // The lines that can be assigned. Sent with the roster rather
            // than fetched separately, because a picker offering a number
            // this account does not own produces a call the carrier rejects
            // — and the two lists would have to be reconciled anyway.
            'numbers' => PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->orderBy('e164')
                ->get()
                ->map(fn (PhoneNumber $number) => [
                    'id' => $number->id,
                    'e164' => $number->e164,
                    'label' => $number->label,
                ])
                ->all(),
        ]);
    }

    /**
     * PUT /api/v1/phone/extensions
     *
     * Give somebody an extension, or take theirs away.
     *
     * One call for both because they are the same decision from the screen's
     * side — a field with digits in it or a field somebody cleared.
     */
    public function update(Request $request): JsonResponse
    {
        $this->mayOpenUsersOrSettings($request);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],

            // Digits only, and a LEADING ZERO. Letters would be unreachable
            // from a phone keypad, which is the one place this has to work;
            // the zero is what tells an extension apart from a menu key while
            // somebody is typing. See PhoneExtension::PATTERN.
            'extension' => ['present', 'nullable', 'string', 'regex:'.PhoneExtension::PATTERN],

            // The line this person's outbound calls go out from. Independent
            // of the extension: somebody can be given a number to call from
            // without being reachable on a short code, and requiring an
            // extension for that would force made-up digits on them.
            'number_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        $user = User::query()->find($input['user_id']);
        abort_if($user === null, Response::HTTP_NOT_FOUND);

        $digits = $input['extension'] ?? null;
        $digits = $digits === '' ? null : $digits;

        $numberId = array_key_exists('number_id', $input) ? $input['number_id'] : null;

        // A number from another phone account would be accepted by Twilio and
        // then rejected at call time as a caller ID the account does not own —
        // an outbound call that fails in the carrier rather than here.
        if ($numberId !== null) {
            $number = PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->find($numberId);

            if ($number === null) {
                throw ValidationException::withMessages([
                    'number_id' => 'That number is not on this phone account.',
                ]);
            }
        }

        // Nothing to hold. The row exists to carry an extension or a number,
        // so with neither it is deleted rather than left as an empty record
        // that reads like a person who has been set up.
        if ($digits === null && $numberId === null) {
            PhoneExtension::query()
                ->where('connection_id', $connection->id)
                ->where('user_id', $user->id)
                ->delete();

            return response()->json(['data' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'extension' => null,
                'number_id' => null,
            ]]);
        }

        // Refused rather than silently reassigned. Two people on one extension
        // makes "call 101" ambiguous, and the unique index would throw a 500
        // here rather than say which person already holds it.
        if ($digits !== null) {
            $taken = PhoneExtension::query()
                ->where('connection_id', $connection->id)
                ->where('extension', $digits)
                ->where('user_id', '!=', $user->id)
                ->first();

            if ($taken !== null) {
                throw ValidationException::withMessages([
                    'extension' => $taken->user?->name
                        ? $taken->user->name.' already has extension '.$digits.'.'
                        : 'Extension '.$digits.' is already taken.',
                ]);
            }
        }

        // Deliberately NOT unique. Several people calling out from one
        // published line is the ordinary small-business setup, and forbidding
        // it would model a rule nobody asked for.
        $row = PhoneExtension::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'user_id' => $user->id],
            [
                'organization_id' => $this->tenant->idOrFail(),
                'extension' => $digits,
                'number_id' => $numberId,
            ],
        );

        return response()->json(['data' => [
            'user_id' => $user->id,
            'name' => $user->name,
            'extension' => $row->extension,
            'number_id' => $row->number_id,
        ]]);
    }

    /**
     * Everybody's extension is the Users tab's, and a menu key that rings one
     * is edited in Settings, so either tab opens this; `permission:` takes one.
     */
    private function mayOpenUsersOrSettings(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('phone.users.view') || $user->hasPermission('phone.settings.view'),
            403,
            \App\Support\Access\Refusal::permission('open this tab')->message(),
        );
    }
}
