<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Support\Phone\DialPlan;
use App\Support\Phone\PhoneScope;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ring groups — who a line rings, and what happens when nobody answers
 * (spec §7, decision 5).
 *
 * ── Why this had to exist before Phone could ring ────────────────
 *
 * TwimlRenderer resolves a group from `phone_numbers.ring_group_id` and falls
 * through to voicemail when it is null. DialPlan already expands a group to
 * available Client identities, caps each <Dial> at ten and batches the
 * remainder. Both were built; nothing could create a group, so `ring_group_id`
 * was never anything but null and every inbound call went to voicemail on a
 * fully configured account.
 *
 * A group names PEOPLE and ROLES, not numbers. Who may ANSWER a line is the
 * per-number grant (§6) and is a separate question — being in a ring group
 * does not confer it, and DialPlan filters to whoever is actually registered
 * on that connection at the moment the call lands.
 */
class RingGroupsController extends Controller
{
    use ResolvesPhoneConnections;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/ring-groups */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneRingGroup::class);

        $connectionIds = $this->connectionsInReach($request->user())->pluck('id')->all();

        $rows = PhoneScope::apply(
            PhoneRingGroup::query()->whereIn('connection_id', $connectionIds),
            $request->user(),
        )
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneRingGroup $group) => $this->row($group))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/phone/members
     *
     * People who could be put in a ring group, and the roles they hold.
     *
     * Presence cannot answer this: it only knows who has registered a softphone
     * at least once, so on a phone system nobody has used yet it is empty — and
     * an empty member picker is how a ring group ends up with nobody in it.
     *
     * Names and ids only. This is not a staff directory: it is the smallest
     * answer to "who can I put on this line", offered to somebody who already
     * holds phone.edit.
     */
    public function members(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneRingGroup::class);

        $user = $request->user();

        $people = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_id'])
            ->map(fn (User $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'role_id' => $row->role_id,
            ])
            ->all();

        $roles = Role::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->all();

        return response()->json(['data' => ['people' => $people, 'roles' => $roles]]);
    }

    /** POST /api/v1/phone/ring-groups */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);

        $this->authorize('create', [PhoneRingGroup::class, $connection]);
        $this->assertOverflowIsUsable($input, $connection, null);

        [$scopeType, $scopeId] = PhoneScope::filing($request->user());

        $group = PhoneRingGroup::create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'connection_id' => $connection->id,
            'name' => trim((string) $input['name']),
            'mode' => $input['mode'] ?? PhoneRingGroup::MODE_SIMULTANEOUS,
            'timeout_seconds' => $input['timeout_seconds'] ?? 30,
            'member_user_ids' => array_values($input['member_user_ids'] ?? []),
            'member_role_ids' => array_values($input['member_role_ids'] ?? []),
            'overflow' => $input['overflow'] ?? PhoneRingGroup::OVERFLOW_VOICEMAIL,
            'overflow_group_id' => $input['overflow_group_id'] ?? null,
            'overflow_number' => $input['overflow_number'] ?? null,
            'queue_max_seconds' => $input['queue_max_seconds'] ?? 300,
            'queue_announce_position' => $input['queue_announce_position'] ?? true,
            'queue_announce_wait' => $input['queue_announce_wait'] ?? false,
            'queue_hold_music_url' => $input['queue_hold_music_url'] ?? null,
        ]);

        return response()->json(['data' => $this->row($group->fresh())], Response::HTTP_CREATED);
    }

    /** GET /api/v1/phone/ring-groups/{group} */
    public function show(PhoneRingGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json(['data' => $this->row($group)]);
    }

    /** PATCH /api/v1/phone/ring-groups/{group} */
    public function update(Request $request, PhoneRingGroup $group): JsonResponse
    {
        $this->authorize('update', $group);

        $input = $request->validate($this->rules(creating: false));
        $this->assertOverflowIsUsable($input, $group->connection, $group);

        foreach ([
            'name', 'mode', 'timeout_seconds',
            'member_user_ids', 'member_role_ids',
            'overflow', 'overflow_group_id', 'overflow_number',
            'queue_max_seconds', 'queue_announce_position', 'queue_announce_wait',
            'queue_hold_music_url',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $group->{$field} = is_string($input[$field]) && $field === 'name'
                    ? trim($input[$field])
                    : $input[$field];
            }
        }

        $group->save();

        return response()->json(['data' => $this->row($group->fresh())]);
    }

    /** DELETE /api/v1/phone/ring-groups/{group} */
    public function destroy(PhoneRingGroup $group): JsonResponse
    {
        $this->authorize('delete', $group);

        // A number pointing at a deleted group would fall through to voicemail
        // with no sign of why, so the lines are named and the delete refused.
        // Detaching them silently would be worse: the phone stops ringing and
        // nothing on screen changed.
        $lines = PhoneNumber::query()
            ->where('ring_group_id', $group->id)
            ->pluck('e164')
            ->all();

        if ($lines !== []) {
            throw ValidationException::withMessages([
                'ring_group' => 'This group still answers '.implode(', ', $lines)
                    .'. Point those lines somewhere else first.',
            ]);
        }

        // Another group's overflow pointing here has the same problem.
        $overflowing = PhoneRingGroup::query()
            ->where('overflow_group_id', $group->id)
            ->pluck('name')
            ->all();

        if ($overflowing !== []) {
            throw ValidationException::withMessages([
                'ring_group' => 'This group is where '.implode(', ', $overflowing)
                    .' sends unanswered calls. Change that first.',
            ]);
        }

        $group->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'connection_id' => $creating
                ? ['required', 'uuid', $this->existsInTenant('phone_connections')]
                : ['prohibited'],
            'name' => [$required, 'string', 'max:191'],
            'mode' => ['sometimes', Rule::in(PhoneRingGroup::MODES)],

            // Twilio hangs up a Voice SDK leg at 60s, so a longer ring is a
            // promise the carrier will not keep.
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:60'],

            'member_user_ids' => ['sometimes', 'array'],
            'member_user_ids.*' => ['uuid', $this->existsInTenant('users')],
            'member_role_ids' => ['sometimes', 'array'],
            'member_role_ids.*' => ['uuid', $this->existsInTenant('roles')],

            'overflow' => ['sometimes', Rule::in(PhoneRingGroup::OVERFLOWS)],
            'overflow_group_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_ring_groups')],
            'overflow_number' => ['sometimes', 'nullable', 'string', 'max:16'],

            // Five minutes by default, and capped at thirty. A caller held
            // longer than that has not been queued, they have been abandoned
            // with music on.
            'queue_max_seconds' => ['sometimes', 'integer', 'min:30', 'max:1800'],
            'queue_announce_position' => ['sometimes', 'boolean'],
            'queue_announce_wait' => ['sometimes', 'boolean'],
            'queue_hold_music_url' => ['sometimes', 'nullable', 'url', 'max:512'],
        ];
    }

    /**
     * An overflow that names nothing is a group that silently drops calls.
     *
     * `forward` without a number and `group` without a group both render as a
     * plain voicemail in TwimlRenderer, which looks like it worked. Refusing at
     * save time is the only place somebody finds out.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertOverflowIsUsable(array $input, ?PhoneConnection $connection, ?PhoneRingGroup $group): void
    {
        $overflow = $input['overflow']
            ?? $group?->overflow
            ?? PhoneRingGroup::OVERFLOW_VOICEMAIL;

        if ($overflow === PhoneRingGroup::OVERFLOW_FORWARD) {
            $number = $input['overflow_number'] ?? $group?->overflow_number;

            if (! is_string($number) || trim($number) === '') {
                throw ValidationException::withMessages([
                    'overflow_number' => 'Say which number unanswered calls should go to.',
                ]);
            }
        }

        if ($overflow === PhoneRingGroup::OVERFLOW_GROUP) {
            $nextId = $input['overflow_group_id'] ?? $group?->overflow_group_id;

            if (! is_string($nextId) || $nextId === '') {
                throw ValidationException::withMessages([
                    'overflow_group_id' => 'Say which group unanswered calls should go to.',
                ]);
            }

            if ($group !== null && $nextId === $group->id) {
                throw ValidationException::withMessages([
                    'overflow_group_id' => 'A group cannot send unanswered calls to itself.',
                ]);
            }

            $next = PhoneRingGroup::query()->find($nextId);

            // Across connections the Client identities belong to a different
            // Twilio account, so the Dial would name people who cannot be
            // reached from this call.
            if ($next === null || $next->connection_id !== $connection?->id) {
                throw ValidationException::withMessages([
                    'overflow_group_id' => 'That group belongs to a different phone account.',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(PhoneRingGroup $group): array
    {
        $connection = $group->connection;

        return [
            'id' => $group->id,
            'connection_id' => $group->connection_id,
            'name' => $group->name,
            'mode' => $group->mode,
            'timeout_seconds' => (int) $group->timeout_seconds,
            'member_user_ids' => $group->member_user_ids ?? [],
            'member_role_ids' => $group->member_role_ids ?? [],
            'overflow' => $group->overflow,
            'overflow_group_id' => $group->overflow_group_id,
            'overflow_number' => $group->overflow_number,
            'queue_max_seconds' => (int) $group->queue_max_seconds,
            'queue_announce_position' => (bool) $group->queue_announce_position,
            'queue_announce_wait' => (bool) $group->queue_announce_wait,
            'queue_hold_music_url' => $group->queue_hold_music_url,

            // WHO THIS GROUP ACTUALLY REACHES, not who is listed in it.
            //
            // A group of four can ring nobody — all at Do Not Disturb, all on
            // other calls, or nobody ever registered a softphone — and the
            // screen showed four names either way. The one place that was
            // knowable was inside DialPlan at the moment a real caller was
            // already on the line.
            //
            // Asked of DialPlan rather than derived from a presence list here,
            // so the column cannot drift from who gets dialled.
            'members' => $connection === null
                ? []
                : DialPlan::roster($group, $connection),

            // Stamped, because this is a MEASUREMENT and not a property.
            // Somebody's phone can register a second after this is read, and a
            // reachability column with no time on it reads as live.
            'members_measured_at' => now()->toIso8601String(),
        ];
    }
}
