<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\Role;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\PermissionCatalogue;
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
 */
class RolesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionCatalogue $catalogue,
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

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id'               => $role->id,
                'name'             => $role->name,
                'is_administrator' => $role->isAdministrator(),
                'user_count'       => $role->users_count,

                // Administrator holds everything implicitly and owns no rows, so
                // reporting its stored (empty) set would draw an editor with
                // nothing ticked for the role that can do the most. It reports
                // the full catalogue instead — the same reasoning as /me, and
                // the same reason there is no wildcard anywhere in this API.
                'permissions' => $role->isAdministrator()
                    ? $this->catalogue->keys()
                    : $role->permissionKeys(),

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
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return response()->json([
            'modules' => $this->catalogue->grantableTreeFor($request->user()),
        ]);
    }

    /** POST /api/v1/roles */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $input = $this->validated($request, required: true);
        $actor = $request->user();

        $requested = $input['permissions'] ?? [];
        $this->catalogue->assertGrantable($actor, $requested);

        $role = DB::transaction(function () use ($input, $requested, $actor) {
            $role = Role::create([
                'organization_id' => $actor->organization_id,
                'name'            => trim($input['name']),
                'is_system'       => false,
            ]);

            $changed = $role->syncPermissionKeys($requested);

            // One entry, with the permissions in it. A "role created" line
            // followed by nothing about what it can do is the entry somebody
            // reads six months later and learns nothing from.
            $this->audit->log('role.created', $role, metadata: [
                'permissions' => $changed['added'],
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

            if (! array_key_exists('permissions', $input)) {
                return;
            }

            $this->catalogue->assertGrantable($actor, $input['permissions']);

            $changed = $role->syncPermissionKeys([
                ...$input['permissions'],
                ...$this->catalogue->preservedFor($role, $actor),
            ]);

            if ($changed['added'] === [] && $changed['removed'] === []) {
                return;
            }

            // Its own action, and both directions recorded. "Permissions
            // changed" with no list is the one audit entry somebody will
            // actually need and cannot use — the question is never whether a
            // role was edited, it is what it gained.
            $this->audit->log('role.permissions_changed', $role, changes: $changed);
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
        ]);
    }

    private function present(Role $role): array
    {
        return [
            'id'               => $role->id,
            'name'             => $role->name,
            'is_administrator' => $role->isAdministrator(),
            'permissions'      => $role->permissionKeys(),
        ];
    }
}
