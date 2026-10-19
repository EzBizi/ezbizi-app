<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\InboxAccess;
use App\Support\Audit\AuditLogger;
use App\Support\Inspection\InspectionGrantAccess;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The organization builds its own roles (plan §4).
 *
 * ── Why this is the missing half of the whole access model ───────
 *
 * Until this existed there was no way to add a second person to an organization
 * with anything other than full control: `Role::create` appeared once, inside
 * ProvisionOrganization, and `permission_role` was written by no code path at
 * all. Every tenant was permanently a single Administrator, which meant every
 * permission key the software defines was ungrantable to anybody, and every
 * `authorize()` call in the application was — in practice — unreachable.
 *
 * There are no preset roles. The owner's decision: every organization composes
 * its own from what the modules declare, and the only built-in is Administrator.
 *
 * ── The four rules, and where each one is enforced ───────────────
 *
 *   Administrator is immutable          RolePolicy (403, with a sentence)
 *   No granting what you do not hold    PermissionCatalogue::assertGrantable (422)
 *   A role in use cannot be deleted     here (422) — see destroy()
 *   Names unique per organization       the validator, backed by a unique index
 *
 * "In use" grew a second meaning when Human Resources arrived: a role is also in
 * use by an invitation nobody has accepted yet. Both are refused in destroy(),
 * for the same reason and with two different sentences.
 *
 * The split between 403 and 422 is deliberate and consistent: 403 means the
 * TARGET is off limits, 422 means the VALUES are. An administrator editing a
 * role they are allowed to edit, who ticks one box too many, has not done
 * anything forbidden — they have sent a list that needs one item removed, and
 * the response names it.
 *
 * ── A role holds two kinds of thing, and only one is a key ───────
 *
 * `permissions` is what the software can do, declared in code, identical for
 * every tenant. `inboxes`, `inspections`, and `phone_numbers` are which of THIS
 * organization's mailboxes, named inspections, or phone lines a role may use —
 * tenant records, not permission keys. Absent means leave them alone; present
 * means this is the complete set within the window of what the caller may grant.
 */
class RolesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionCatalogue $catalogue,
        private readonly InboxAccess $inboxes,
        private readonly InspectionGrantAccess $inspections,
        private readonly PhoneNumberAccess $phoneNumbers,
    ) {}

    /**
     * GET /api/v1/roles
     *
     * With user counts, because the count is the thing that decides whether a
     * role can be deleted — and finding that out by trying is a poor way to run
     * a screen.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $user = $request->user();

        $roles = Role::query()->withCount('users')->orderBy('name')->get();

        // Every role's inbox grants in one query rather than one per row. The
        // list draws all of them, and a screen that costs a query per role is a
        // screen that gets slower as an organization gets more careful about
        // access.
        $inboxes = $this->inboxes->heldByRoles($roles);
        $inspections = $this->inspections->heldByRoles($roles);
        $phoneNumbers = $this->phoneNumbers->heldByRoles($roles);

        // Who holds each role, for the faces on the redesigned role cards (UI
        // queue 111). One query for every role, grouped here, for the reason
        // above. Every holder rather than only active ones, so the names are
        // drawn from the same set `user_count` counts; capped at eight because
        // the card draws a row of faces, and the count says how many more.
        $holders = User::query()
            ->whereIn('role_id', $roles->modelKeys())
            ->orderBy('name')
            ->get(['id', 'name', 'role_id'])
            ->groupBy('role_id')
            ->map(fn ($users) => $users->take(8)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all());

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id'               => $role->id,
                'name'             => $role->name,
                'is_administrator' => $role->isAdministrator(),
                'user_count'       => $role->users_count,
                'people'           => $holders[$role->id] ?? [],

                // Which roles its holders may offer when they invite, or null
                // for "any role reaching no further than theirs".
                'invitable_roles'  => $role->invitable_role_ids,
                'hidden_on_leads'  => (bool) $role->hidden_on_leads,

                // Administrator holds everything implicitly and owns no rows, so
                // reporting its stored (empty) set would draw an editor with
                // nothing ticked for the role that can do the most. It reports
                // the full catalogue instead — the same reasoning as /me, and
                // the same reason there is no wildcard anywhere in this API.
                'permissions' => $role->isAdministrator()
                    ? $this->catalogue->keys()
                    : $role->permissionKeys(),

                // The mailbox ids this role may open. Ids rather than addresses:
                // this is what the editor ticks and what a save sends back, and
                // an address is a label that can be changed. The addresses to
                // draw beside them come from the catalogue, once, rather than
                // being repeated on every role.
                'inboxes' => $inboxes[$role->id] ?? [],
                'inspections' => $inspections[$role->id] ?? [],
                'phone_numbers' => $phoneNumbers[$role->id] ?? [],

                'may_edit'   => $user->can('update', $role),
                'may_delete' => $user->can('delete', $role),
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/roles/catalogue
     *
     * The grantable tree, derived from the module registry rather than kept by
     * hand here. A module that declares a new action appears in the editor on
     * the deploy that adds it, with no edit to this file — which is the §5.5
     * standing rule and the §2.1 no-hardcoding rule meeting at the same place.
     *
     * ── And then composed with the organization's own inboxes ────
     *
     * The static tree still comes from PermissionCatalogue, unchanged and
     * complete. InboxAccess hangs this tenant's shared mailboxes off the one
     * module that owns them, so the editor draws
     *
     *     Email
     *       * info@a-1chimney.com
     *
     * from two sources that stay separate underneath: a platform permission
     * catalogue every tenant shares, and tenant records no other tenant can see.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $actor = $request->user();

        return response()->json([
            'modules' => $this->phoneNumbers->compose(
                $this->inspections->compose(
                    $this->inboxes->compose(
                        $this->catalogue->grantableTreeFor($actor),
                        $actor,
                    ),
                    $actor,
                ),
                $actor,
            ),
        ]);
    }

    /** POST /api/v1/roles */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $input = $this->validated($request, required: true);
        $actor = $request->user();

        $requested = $input['permissions'] ?? [];
        $inboxes   = $input['inboxes'] ?? [];
        $inspections = $input['inspections'] ?? [];
        $phoneNumbers = $input['phone_numbers'] ?? [];

        $this->catalogue->assertGrantable($actor, $requested);
        $this->catalogue->assertRequirementsMet($requested);
        $this->inboxes->assertGrantable($actor, $inboxes);
        $this->inspections->assertGrantable($actor, $inspections);
        $this->phoneNumbers->assertGrantable($actor, $phoneNumbers);

        $role = DB::transaction(function () use ($input, $requested, $inboxes, $inspections, $phoneNumbers, $actor) {
            $role = Role::create([
                'organization_id' => $actor->organization_id,
                'name'            => trim($input['name']),
                'is_system'       => false,
            ]);

            $changed = $role->syncPermissionKeys($requested);

            // AFTER the permissions, because "may this role open the Email tab"
            // is a question about the role as it will be. Creating a role with
            // the tab and an inbox under it in one request is the ordinary case.
            $this->inboxes->assertReachable($role, $inboxes);
            $mail = $this->inboxes->sync($role, $inboxes);
            $this->inspections->assertReachable($role, $inspections);
            $offered = $this->inspections->sync($role, $inspections);
            $this->phoneNumbers->assertReachable($role, $phoneNumbers);
            $lines = $this->phoneNumbers->sync($role, $phoneNumbers);

            $this->audit->log('role.created', $role, metadata: [
                'permissions' => $changed['added'],
                'inboxes' => $mail['added'],
                'inspections' => $offered['added'],
                'phone_numbers' => $lines['added'],
            ]);

            return $role;
        });

        return response()->json(['data' => $this->present($role)], 201);
    }

    /**
     * PATCH /api/v1/roles/{role}
     *
     * `permissions` is the COMPLETE set for this role, not a delta — but only
     * within the caller's own permissions. See PermissionCatalogue::preservedFor
     * for what sits outside that window and why omitting it is not a removal.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        // Administrator is refused here, with a sentence saying why (RolePolicy).
        $this->authorize('update', $role);

        $input = $this->validated($request, required: false, ignoring: $role);
        $actor = $request->user();

        DB::transaction(function () use ($input, $role, $actor) {
            if (array_key_exists('name', $input)) {
                $role->name = trim($input['name']);

                if ($role->isDirty('name')) {
                    $this->audit->logChange('role.updated', $role);
                    $role->save();
                }
            }

            if (array_key_exists('permissions', $input)) {
                $this->catalogue->assertGrantable($actor, $input['permissions']);

                // Checked against what will be STORED, not what was sent. The
                // preserved keys are part of the role afterwards, so a
                // requirement they satisfy is satisfied — and one they leave
                // open has to fail here rather than at the next edit.
                $wantedPermissions = [
                    ...$input['permissions'],
                    ...$this->catalogue->preservedFor($role, $actor),
                ];

                $this->catalogue->assertRequirementsMet($wantedPermissions);

                $changed = $role->syncPermissionKeys($wantedPermissions);

                // Its own action, and both directions recorded. "Permissions
                // changed" with no list is the one audit entry somebody will
                // actually need and cannot use — the question is never whether a
                // role was edited, it is what it gained.
                if ($changed['added'] !== [] || $changed['removed'] !== []) {
                    $this->audit->log('role.permissions_changed', $role, changes: $changed);
                }
            }

            if (array_key_exists('inboxes', $input)) {
                $this->inboxes->assertGrantable($actor, $input['inboxes']);

                $wanted = [
                    ...$input['inboxes'],
                    ...$this->inboxes->preservedFor($role, $actor),
                ];

                $this->inboxes->assertReachable($role, $wanted);

                $mail = $this->inboxes->sync($role, $wanted);

                if ($mail['added'] !== [] || $mail['removed'] !== []) {
                    $this->audit->log('role.inboxes_changed', $role, changes: $mail);
                }
            }

            if (array_key_exists('inspections', $input)) {
                $this->inspections->assertGrantable($actor, $input['inspections']);

                $wantedInspections = [
                    ...$input['inspections'],
                    ...$this->inspections->preservedFor($role, $actor),
                ];

                $this->inspections->assertReachable($role, $wantedInspections);

                $offered = $this->inspections->sync($role, $wantedInspections);

                if ($offered['added'] !== [] || $offered['removed'] !== []) {
                    $this->audit->log('role.inspections_changed', $role, changes: $offered);
                }
            }

            if (array_key_exists('invitable_roles', $input)) {
                $wanted = $input['invitable_roles'] === null ? null : array_values(array_unique($input['invitable_roles']));

                foreach (Role::query()->whereKey($wanted ?? [])->get() as $offered) {
                    if (! $this->catalogue->mayListAsInvitable($actor, $offered)) {
                        throw ValidationException::withMessages(['invitable_roles' => sprintf(
                            'You cannot let this role offer %s, because %s reaches further than your own '
                            .'role does.', $offered->name, $offered->name,
                        )]);
                    }
                }

                $before = $role->invitable_role_ids;
                $role->forceFill(['invitable_role_ids' => $wanted])->save();

                if ($before !== $wanted) {
                    $this->audit->log('role.invitable_roles_changed', $role,
                        changes: ['before' => $before, 'after' => $wanted]);
                }
            }

            if (array_key_exists('hidden_on_leads', $input)) {
                $role->hidden_on_leads = (bool) $input['hidden_on_leads'];

                if ($role->isDirty('hidden_on_leads')) {
                    $this->audit->logChange('role.updated', $role);
                    $role->save();
                }
            }

            if (array_key_exists('phone_numbers', $input)) {
                $this->phoneNumbers->assertGrantable($actor, $input['phone_numbers']);

                $wantedNumbers = [
                    ...$input['phone_numbers'],
                    ...$this->phoneNumbers->preservedFor($role, $actor),
                ];

                $this->phoneNumbers->assertReachable($role, $wantedNumbers);

                $lines = $this->phoneNumbers->sync($role, $wantedNumbers);

                if ($lines['added'] !== [] || $lines['removed'] !== []) {
                    $this->audit->log('role.phone_numbers_changed', $role, changes: $lines);
                }
            }
        });

        return response()->json(['data' => $this->present($role->fresh())]);
    }

    /**
     * DELETE /api/v1/roles/{role}
     *
     * ── A role in use is refused, and the reason is the schema ───
     *
     * `users.role_id` is nullable with ON DELETE SET NULL. So deleting a role
     * that people hold does not fail — it quietly empties their role, and
     * `User::hasPermission()` returns false for every key from then on
     * (`$this->role?->hasPermission(...) ?? false`). The result is an account
     * that signs in perfectly, shows an empty application, and says nothing
     * about why. Nobody would connect that to a role somebody deleted.
     *
     * Reassignment is not offered as part of the delete either. "Move these
     * eleven people to Dispatcher" is a decision about eleven people, and
     * burying it in the confirmation dialog of a different action is how it gets
     * made carelessly. Move them first, then the role deletes cleanly.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        /*
         * An invitation nobody has accepted yet holds this role too.
         *
         * `invitations.role_id` is nullable with ON DELETE SET NULL, for the
         * same reason `users.role_id` is: the historical record of who was
         * invited must survive a role being retired years later. But an OPEN
         * invitation whose role evaporates produces the account this method
         * already refuses to create — one that signs in, shows an empty
         * application, and says nothing about why — except that here nobody
         * would even connect it to a role, because the person does not exist yet
         * and the failure surfaces a week later at acceptance.
         *
         * So an outstanding one blocks the delete exactly as a holder does.
         * Accepted, withdrawn and lapsed invitations do not: their role_id going
         * null costs nothing but a name in a list, and refusing on those would
         * make a role with a long history undeletable forever.
         */
        $pending = Invitation::query()
            ->where('role_id', $role->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        if ($pending > 0) {
            throw ValidationException::withMessages(['role' => sprintf(
                '%d %s still waiting to be accepted %s this role. Withdraw %s first, or let '
                .'%s lapse — deleting the role now would leave whoever accepts with an account '
                .'that can do nothing.',
                $pending,
                $pending === 1 ? 'invitation is' : 'invitations are',
                $pending === 1 ? 'for' : 'for',
                $pending === 1 ? 'it' : 'them',
                $pending === 1 ? 'it' : 'them',
            )]);
        }

        $holders = $role->users()->count();

        if ($holders > 0) {
            throw ValidationException::withMessages(['role' => sprintf(
                '%d %s still %s this role. Move them to another role first — deleting it now '
                .'would leave them able to sign in and do nothing, with nothing on screen '
                .'saying why.',
                $holders,
                $holders === 1 ? 'person' : 'people',
                $holders === 1 ? 'has' : 'have',
            )]);
        }

        DB::transaction(function () use ($role) {
            // Logged BEFORE the delete, while the row is still there to name —
            // and inside the transaction, so the record and the change commit
            // together or not at all.
            $this->audit->log('role.deleted', $role, metadata: [
                'permissions' => $role->permissionKeys(),
                // The mail goes with it — `mailbox_grants.role_id` cascades — so
                // this line is the only record left that this role could read
                // somebody's inbox. Addresses rather than ids, for the reason
                // InboxAccess::sync gives: an id here resolves to nothing.
                'inboxes' => $this->inboxes->addressesHeldBy($role),
                'inspections' => $this->inspections->namesHeldBy($role),
                'phone_numbers' => $this->phoneNumbers->labelsHeldBy($role),
            ]);

            $role->permissions()->detach();
            $role->delete();
        });

        return response()->json(['data' => ['id' => $role->id, 'deleted' => true]]);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * Names are unique per ORGANIZATION, not globally — two tenants may each
     * have a "Dispatcher" and neither has heard of the other. Backed by the
     * unique index on (organization_id, name); the rule is here so the answer is
     * a 422 naming the field rather than a database error.
     */
    private function validated(Request $request, bool $required, ?Role $ignoring = null): array
    {
        $unique = Rule::unique('roles', 'name')
            ->where('organization_id', $request->user()->organization_id);

        if ($ignoring !== null) {
            $unique->ignore($ignoring->getKey());
        }

        return $request->validate([
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:60', $unique],

            // Present-but-empty is a real instruction — "this role should hold
            // nothing" — so `array` rather than `filled`, and absent means
            // "leave the permissions alone".
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:120'],

            // The shared inboxes this role may open, by mailbox id, on exactly
            // the terms above: present-and-empty means "no inboxes", absent
            // means "leave them alone". Which ids are real is not a validation
            // rule — it is a tenant question, answered against this
            // organization's own mail in InboxAccess::assertGrantable, where the
            // refusal can say something useful.
            'inboxes'   => ['sometimes', 'array'],
            'inboxes.*' => ['string', 'max:64'],
            'inspections'   => ['sometimes', 'array'],
            'inspections.*' => ['string', 'max:64'],
            'phone_numbers'   => ['sometimes', 'array'],
            'phone_numbers.*' => ['string', 'max:64'],

            // Which roles this role's holders may offer when they invite;
            // null puts it back on the rule by reach.
            'invitable_roles'   => ['sometimes', 'nullable', 'array'],
            'invitable_roles.*' => ['uuid', Rule::exists('roles', 'id')
                ->where('organization_id', $request->user()->organization_id)],

            // Whether its holders are left off "X is viewing this lead".
            'hidden_on_leads' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(Role $role): array
    {
        return [
            'id'               => $role->id,
            'name'             => $role->name,
            'is_administrator' => $role->isAdministrator(),
            'permissions'      => $role->permissionKeys(),
            'inboxes'          => $this->inboxes->heldBy($role),
            'inspections'      => $this->inspections->heldBy($role),
            'phone_numbers'    => $this->phoneNumbers->heldBy($role),
            'invitable_roles'  => $role->invitable_role_ids,
            'hidden_on_leads'  => (bool) $role->hidden_on_leads,
        ];
    }
}
