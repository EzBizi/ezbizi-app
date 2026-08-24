<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AdministratorFloor;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Privacy\ConsentLedger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The people an organization has, and the two things about them that can change
 * (EZBIZI-HR-PLAN.md — Records; §4).
 *
 * ── This is the endpoint that writes `users.role_id` ─────────────
 *
 * Nothing did, before. Roles could be composed and permissions could be
 * granted, and there was nobody to give them to — every organization was
 * permanently its founding Administrator. Two fields move here, and each of
 * them is guarded by a rule that has never been reachable until now:
 *
 *   role_id    bounded by what the caller themselves holds (§4 escalation)
 *   is_active  bounded by last-administrator protection (§4)
 *
 * ── What is deliberately NOT here ────────────────────────────────
 *
 * Names, phone numbers, dates of birth. The plan's one-home rule: core identity
 * lives on the user's own profile and HR points at it rather than copying it.
 * The employee owns it and edits it in "Your account"; an employer editing it
 * here would be the second home, and two homes for one fact is the trap.
 *
 * Assignments, too. Changing where somebody works is a real act with its own
 * consequences and it is not this release — the invitation sets the first one,
 * and moving people between places comes with onboarding.
 */
class HrPeopleController extends Controller
{
    public function __construct(
        private readonly ScopeReach $reach,
        private readonly PermissionCatalogue $catalogue,
        private readonly AdministratorFloor $administrators,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly ConsentLedger $consents,
    ) {}

    /**
     * GET /api/v1/hr/people/{user}/consents — what this person has agreed to (§9).
     *
     * ── Why it hangs off the person and not off a privacy module ──
     *
     * There is one consent in this product and it is taken during onboarding, so
     * the answer belongs beside the person it is about — which is this tab. A
     * `privacy` module would mean a new tab permission and a screen nobody has
     * designed, to separate one answer from the record it describes.
     *
     * Gated by the ordinary `view` policy rather than by something stricter. The
     * caller can already read this person's record; a consent statement is the
     * text this software showed them, not a new disclosure, and refusing it to
     * somebody who may see the contract would be a lock on the door of a room
     * they are standing in.
     *
     * `not_consent` comes back with it, always. See ConsentLedger: the failure
     * mode of a consent list is a reader assuming everything else was consented
     * to as well.
     */
    public function consents(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        // Recorded, on the same reasoning as the data inventory read: "who asked
        // what this person agreed to" is a question a compliance answer should
        // itself be able to answer.
        $this->audit->log('data.consents_read', $user);

        return response()->json($this->consents->forPerson($user));
    }

    /**
     * GET /api/v1/hr/people
     *
     * Deactivated people are included, and that is the point: §5's rule is that
     * deactivate is not delete and the record survives, so a Records tab that
     * hid them would be a list of current staff pretending to be a list of
     * staff. `active_only` is there for the screens that want the shorter one.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $people = User::query()
            ->with(['role:id,name', 'assignments.assignedCompany:id,name',
                'assignments.assignedFranchise:id,name'])
            ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($people->items())->map(fn (User $p) => $this->present($p, $actor)),
            'meta' => [
                'total'        => $people->total(),
                'per_page'     => $people->perPage(),
                'current_page' => $people->currentPage(),
                'last_page'    => $people->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/hr/people/scope-options
     *
     * What THIS caller may assign — both halves of it, because an invitation
     * needs both and a form that offered a valid role with an invalid place
     * would fail on save for a reason the person could not see.
     *
     * The roles come back complete, with an `assignable` flag rather than the
     * ungrantable ones filtered out. Same call the roles editor makes about
     * actions: a role that simply is not in the list leaves somebody unable to
     * tell "we do not have that role" from "I am not allowed to give it".
     */
    public function scopeOptions(Request $request): JsonResponse
    {
        // Both forms need it — inviting somebody, and changing the role of
        // somebody already here — so the gate is either permission rather than
        // `hr.create` alone. See UserPolicy::assignOptions.
        $this->authorize('assignOptions', User::class);

        $actor = $request->user();

        return response()->json([
            'data' => [
                ...$this->reach->optionsFor($actor),

                'roles' => Role::query()->orderBy('name')->get()->map(fn (Role $role) => [
                    'id'               => $role->id,
                    'name'             => $role->name,
                    'is_administrator' => $role->isAdministrator(),
                    'assignable'       => $this->catalogue->mayAssignRole($actor, $role),
                ])->all(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/hr/people/{user}
     *
     * Both fields optional, and sending neither is a no-op rather than an error
     * — a form that submits an unchanged row should not be told off.
     *
     * The four refusals, in the order they are checked and each for its own
     * reason:
     *
     *   403  the person is outside your reach, or you lack `hr.edit`   (policy)
     *   422  you are switching off your own account
     *   422  that role reaches further than yours                      (§4)
     *   422  that would leave the organization with no administrator   (§4)
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();

        $input = $request->validate([
            'role_id' => ['sometimes', 'required', 'uuid', Rule::exists('roles', 'id')
                ->where('organization_id', $this->tenant->idOrFail())],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $deactivating = array_key_exists('is_active', $input)
            && $input['is_active'] === false
            && $user->is_active;

        /*
         * Switching off your own account is refused.
         *
         * Not an escalation and no danger to anybody else — it is a door that
         * locks from the inside with the key on the outside. `hasPermission()`
         * returns false for an inactive user for every key, so the moment it
         * saved, the person would lose the screen they would need to undo it.
         * Last-administrator protection below only catches the case where the
         * ORGANIZATION would be left with nobody; this catches the far commoner
         * one, where somebody switches off the wrong row and it is their own.
         */
        if ($deactivating && $user->is($actor)) {
            throw ValidationException::withMessages(['is_active' =>
                'You cannot switch off your own access. The moment it saved you would lose '
                .'this screen, and somebody else would have to switch you back on. Ask a '
                .'colleague to do it if you mean it.',
            ]);
        }

        $newRole = array_key_exists('role_id', $input) ? Role::findOrFail($input['role_id']) : null;

        if ($newRole !== null && ! $newRole->is($user->role)) {
            // Giving somebody a role hands them every permission it holds
            // without naming one of them. Same bound as the roles editor.
            $this->catalogue->assertRoleAssignable($actor, $newRole);

            // §4: an organization must never be left with no administrator.
            // Moving the last one onto a different role is one of the two ways
            // to do that, and it is the way nobody thinks of.
            if (! $newRole->isAdministrator()) {
                $this->administrators->assertNotTheLast($user, 'role_id');
            }
        }

        if ($deactivating) {
            $this->administrators->assertNotTheLast($user, 'is_active');
        }

        DB::transaction(function () use ($user, $input, $actor) {
            $user->fill($input);

            $changed = $user->getDirty();

            if ($changed === []) {
                return;
            }

            // Captured BEFORE the save. `save()` syncs the original state, so
            // `getOriginal('role_id')` afterwards answers with the NEW value —
            // an audit entry recording that the role changed from itself to
            // itself, which is worse than none.
            $before = array_intersect_key($user->getOriginal(), $changed);

            $this->audit->logChange('user.updated', $user);

            $user->save();

            if (array_key_exists('is_active', $changed)) {
                \App\Models\EmploymentEvent::create([
                    'organization_id' => $user->organization_id,
                    'user_id'         => $user->id,
                    'kind'            => $user->is_active
                        ? \App\Models\EmploymentEvent::STARTED
                        : \App\Models\EmploymentEvent::LEFT,
                    'occurred_on'     => now()->toDateString(),
                ]);

                /*
                 * The two events, and the one that is easy to get subtly wrong.
                 *
                 * `user.activated` fires for a reactivation as well as for an
                 * acceptance — the description in the registry says so — because the
                 * end state is the same and there is no second event for it. A rule
                 * that says "when somebody's account starts working, tell their
                 * manager" must fire both times or it is wrong half the time.
                 */
                $this->events->emit(
                    $user->is_active ? 'user.activated' : 'user.deactivated',
                    $user,
                    [
                        'before' => ['is_active' => (bool) ($before['is_active'] ?? false)],
                        'after'  => ['is_active' => $user->is_active],
                    ],
                    actor: $actor,
                );

                if (! $user->is_active) {
                    $user->tokens()->delete();
                }
            }

            if (array_key_exists('role_id', $changed)) {
                // Its own audit action as well as the generic `user.updated`
                // above. "Who made Marta an administrator" is the question that
                // actually gets asked, and a line saying "user updated" cannot
                // answer it — the same reasoning as role.permissions_changed.
                $this->audit->log('user.role_changed', $user, changes: [
                    'before' => ['role_id' => $before['role_id'] ?? null],
                    'after'  => ['role_id' => $user->role_id],
                ]);
            }
        });

        return response()->json([
            'data' => $this->present($user->fresh()->load('role', 'assignments'), $actor),
        ]);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * The people this caller is allowed to see, as a predicate.
     *
     * Three arms, and each is the SQL twin of a branch in UserPolicy — they are
     * written apart because a list has to be filtered in the database to
     * paginate, and they are kept honest by both being built out of ScopeReach
     * rather than out of each other.
     *
     *   yourself           always, whatever your assignments say; a Records tab
     *                      that does not contain the person reading it looks
     *                      broken
     *   anybody assigned   to a place your authority covers
     *   the unassigned     only if you reach the whole organization, because
     *                      there is no smaller place to check them against
     */
    private function visibleTo(Builder $query, User $actor): void
    {
        $query->whereKey($actor->getKey())
            ->orWhereHas('assignments', fn (Builder $a) => $this->reach->constrain($a, $actor));

        if ($actor->canActOnOrganization($actor->organization)) {
            $query->orWhereDoesntHave('assignments');
        }
    }

    /**
     * @param  User  $actor  the reader — their reach decides which of this
     *                       person's places are named. Somebody who runs
     *                       Argentina may see the person working there without
     *                       learning that they also work in France.
     */
    private function present(User $person, User $actor): array
    {
        return [
            'id'         => $person->id,
            'name'       => $person->name,
            'first_name' => $person->first_name,
            'last_name'  => $person->last_name,
            'email'      => $person->email,
            'is_active'  => $person->is_active,
            'role'       => [
                'id'   => $person->role_id,
                'name' => $person->role?->name,
            ],
            'assignments' => $person->assignments
                ->filter(fn (Assignment $a) => $this->reach->coversAssignment($actor, $a))
                ->map(fn (Assignment $a) => [
                    'id'       => $a->id,
                    'type'     => $a->scopeType(),
                    'scope_id' => $a->scopeId(),
                    'name'     => match ($a->scopeType()) {
                        Assignment::SCOPE_ORGANIZATION => $actor->organization->name,
                        Assignment::SCOPE_COMPANY      => $a->assignedCompany?->name,
                        Assignment::SCOPE_FRANCHISE    => $a->assignedFranchise?->name,
                    },
                ])->values()->all(),
            'joined_at' => $person->created_at?->toIso8601String(),
        ];
    }
}
