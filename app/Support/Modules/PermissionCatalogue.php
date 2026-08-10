<?php

namespace App\Support\Modules;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What may be granted, what a person holds, and what they can reach (plan §4).
 *
 * ── Why one class and not three ──────────────────────────────────
 *
 * Four questions used to be answered in four places, and they have to agree or
 * the product lies to somebody:
 *
 *   1. what can be granted at all           — the roles editor
 *   2. what does this person effectively hold — /me, and every authorize()
 *   3. which modules may they reach          — the client's navigation
 *   4. what may THEY grant to somebody else  — the escalation bound
 *
 * (2) and (3) disagreeing means a tab the client draws and the server refuses.
 * (1) and (4) disagreeing means an editor that offers a checkbox the save then
 * rejects. So all four are derived here, from the same two inputs: the module
 * registry (what the software can do — §2.1, a platform invariant that stays in
 * code) and ModuleAvailability (what the platform currently permits this
 * organization — §13).
 *
 * Nothing here is a hand-kept list. A module that declares a new action appears
 * in the catalogue, in /me and in the editor with no edit to this file.
 */
class PermissionCatalogue
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAvailability $availability,
    ) {}

    // ── the catalogue itself ─────────────────────────────────────

    /** Every permission key the software declares, sorted. */
    public function keys(): array
    {
        return $this->registry->allPermissionKeys();
    }

    /** `customers.create` → `customers`. */
    public function moduleOf(string $permissionKey): string
    {
        return str_contains($permissionKey, '.')
            ? strstr($permissionKey, '.', before_needle: true)
            : $permissionKey;
    }

    /**
     * Add each module's view to a set of keys, and drop anything the registry
     * does not declare.
     *
     * This is the §4 invariant made true in the DATA rather than in the UI: a
     * role cannot be stored holding `customers.edit` without `customers.view`,
     * so "may edit, tab hidden" is not a state the system can represent. The
     * client is not the place to keep an invariant — a second client, an import,
     * or a curl request would each have to re-implement it, and one of them
     * would get it wrong.
     *
     * Unknown keys are dropped rather than refused, because this is also the
     * normaliser for keys already in the database: a permission removed from the
     * registry must stop granting anything, not crash every role that holds it.
     * Refusing unknown keys on the way IN is the controller's job, where the
     * caller can be told which one was wrong.
     *
     * @param  string[]  $keys
     * @return string[] sorted, unique
     */
    public function withImpliedView(array $keys): array
    {
        $declared = $this->keys();
        $result   = [];

        foreach ($keys as $key) {
            if (! in_array($key, $declared, true)) {
                continue;
            }

            $result[] = $key;
            // Guaranteed to exist: ModuleDefinition refuses a module without one.
            $result[] = $this->moduleOf($key).'.view';
        }

        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    /**
     * The permission row for a key, created from the registry if absent.
     *
     * The registry is the source of truth; the row is a join-table artefact that
     * `ezbizi:sync-permissions` normally materialises on deploy. Creating a
     * missing one here is not inventing a permission — it is writing down one the
     * software already declares — and it means a role save cannot fail because a
     * deploy step was skipped. The label is decided here so the command and this
     * path cannot word the same permission two ways.
     */
    public function materialise(string $key): Permission
    {
        $moduleKey = $this->moduleOf($key);
        $module    = $this->registry->get($moduleKey);
        $action    = substr($key, strlen($moduleKey) + 1);

        if ($module === null || ! in_array($action, $module->actions, true)) {
            throw new InvalidArgumentException(
                "Unknown permission '{$key}'. Permissions come from the module registry, "
                .'which lives in code — a key with nothing behind it enforces nothing.'
            );
        }

        return Permission::firstOrCreate(
            ['key' => $key],
            [
                'module' => $moduleKey,
                'action' => $action,
                'label'  => $this->labelFor($module, $action),
            ],
        );
    }

    /** `View customers` — the sentence a permission reads as on its own. */
    public function labelFor(ModuleDefinition $module, string $action): string
    {
        return ucfirst($action).' '.lcfirst($module->label);
    }

    /** `View` — the word beside the checkbox, where the module already names itself. */
    public function actionLabel(string $action): string
    {
        return ucfirst(str_replace('_', ' ', $action));
    }

    // ── what a person holds ──────────────────────────────────────

    /**
     * The permission keys this user EFFECTIVELY holds.
     *
     * "Effectively" is the whole point: this must agree, key for key, with
     * `User::hasPermission()`. The client decides what to draw from this list and
     * the server decides what to allow from that method, so a module the platform
     * has switched off has to drop out of both — otherwise the client renders a
     * control that answers 403.
     *
     * For an Administrator this expands to the full catalogue rather than a
     * wildcard. A wildcard would force every consumer to special-case a role, and
     * a client that special-cases a role name is the bug this whole task exists
     * to fix.
     *
     * @return string[] sorted
     */
    public function heldBy(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        $keys = $user->isAdministrator()
            ? $this->keys()
            : array_values(array_intersect($this->keys(), $user->role?->permissionKeys() ?? []));

        return array_values(array_filter(
            $keys,
            fn (string $key) => $this->availability->isPermissionAvailable($key, $user->organization),
        ));
    }

    /**
     * The modules, with the two independent facts the client needs about each.
     *
     *   available — the PLATFORM permits it for this organization (§13)
     *   reachable — AND this person's role holds the module's view permission
     *
     * Carried separately, and never collapsed into one flag, because a screen
     * that cannot be reached for the two reasons needs to say two different
     * things: "your plan does not include this" is a sales conversation, and
     * "your role does not include this" is a conversation with the person's own
     * administrator. Omitting the module entirely says neither.
     */
    public function modulesFor(User $user): array
    {
        $held = $this->heldBy($user);

        return array_values(array_map(function (ModuleDefinition $module) use ($user, $held) {
            $available = $this->availability->isAvailable($module->key, $user->organization);

            return [
                'key'       => $module->key,
                'label'     => $module->label,
                'available' => $available,
                // The tab IS the view permission (§4). `heldBy` has already
                // dropped unavailable modules, so this cannot be true while
                // `available` is false — but it is written as a conjunction
                // anyway, because a reader should not have to know that.
                'reachable' => $available && in_array("{$module->key}.view", $held, true),
            ];
        }, $this->registry->all()));
    }

    // ── what a person may grant ──────────────────────────────────

    /**
     * The grantable tree the roles editor is built from.
     *
     * Every module appears, including ones the platform has switched off, so the
     * editor can show them as unavailable rather than silently omitting them —
     * an administrator who cannot see a module has no way to tell "we don't have
     * this" from "I've lost my permission to configure it".
     *
     * `grantable` is per action rather than per module, because the escalation
     * bound is per action: someone who holds `customers.view` and `customers.edit`
     * may grant exactly those two and not `customers.delete`.
     */
    public function grantableTreeFor(User $user): array
    {
        $held = $this->heldBy($user);

        return array_values(array_map(function (ModuleDefinition $module) use ($user, $held) {
            $available = $this->availability->isAvailable($module->key, $user->organization);

            return [
                'key'       => $module->key,
                'label'     => $module->label,
                'available' => $available,
                'actions'   => array_map(fn (string $action) => [
                    'key'       => "{$module->key}.{$action}",
                    'action'    => $action,
                    'label'     => $this->actionLabel($action),
                    'grantable' => in_array("{$module->key}.{$action}", $held, true),
                ], $module->actions),
            ];
        }, $this->registry->all()));
    }

    /**
     * Refuse anything the caller is not entitled to hand out.
     *
     * §4, the owner's words: nobody can grant a permission they do not themselves
     * hold. An Administrator holds the whole catalogue, so today they are
     * unbounded in practice — but the rule is written here rather than left
     * implied by "only administrators can reach the roles screen", because the
     * day a Manager role is given `roles.edit` that implication silently becomes
     * a privilege-escalation path, and nothing in the code would have changed.
     *
     * Three refusals, deliberately worded apart. "That permission does not exist",
     * "the platform has this module switched off" and "you do not hold this
     * yourself" are three different problems with three different fixes, and one
     * generic message sends the administrator looking in the wrong place.
     *
     * @param  string[]  $keys  the keys being granted
     *
     * @throws ValidationException
     */
    public function assertGrantable(User $actor, array $keys): void
    {
        /*
         * Normalised BEFORE it is checked, because normalising afterwards was a
         * hole.
         *
         * This validated the list the caller SENT, and Role::syncPermissionKeys
         * then stored withImpliedView($keys) — a different, larger list. The
         * escalation bound was therefore asserted against the wrong one: an
         * actor holding `customers.edit` and not `customers.view` could grant
         * `customers.edit`, and the implied-view rule silently added
         * `customers.view`, a key they do not hold. Confirmed over HTTP: 201,
         * with both keys stored.
         *
         * Unreachable through today's API, because every write path this app
         * owns normalises and so cannot produce the precondition. That is not a
         * reason to leave it: the stated guarantee is that the invariant lives
         * in the DATA, and syncPermissionKeys' own docblock names the writers
         * this has to survive — "an importer, an HR bulk action, a seeder". The
         * HR module now being built is the first of them.
         *
         * One line, and the check and the write now agree on what is being
         * checked.
         */
        $keys = $this->withImpliedView($keys);

        $declared = $this->keys();
        $held     = $this->heldBy($actor);

        $unknown = array_values(array_diff($keys, $declared));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => sprintf(
                'No such permission: %s. Permissions come from the modules themselves, so this '
                .'is either a typo or a module that is no longer installed.',
                implode(', ', $unknown),
            )]);
        }

        $unavailable = array_values(array_filter(
            $keys,
            fn (string $key) => ! $this->availability->isPermissionAvailable($key, $actor->organization),
        ));

        if ($unavailable !== []) {
            throw ValidationException::withMessages(['permissions' => sprintf(
                'EzBizi has switched off the module behind %s for this organization, so it '
                .'cannot be granted to anybody. Existing roles keep it and will work again the '
                .'moment it is switched back on.',
                implode(', ', $unavailable),
            )]);
        }

        $beyond = array_values(array_diff($keys, $held));

        if ($beyond !== []) {
            throw ValidationException::withMessages(['permissions' => sprintf(
                'You cannot grant %s, because your own role does not hold it. Nobody can hand '
                .'out more than they have.',
                implode(', ', $beyond),
            )]);
        }
    }

    /**
     * The permissions on a role that this caller's edit must LEAVE ALONE.
     *
     * The mirror of the escalation bound. The caller's window is exactly their
     * own permission set: inside it their submitted list is the whole truth,
     * outside it nothing is added and nothing is removed.
     *
     * Two cases fall outside, and both would be data loss:
     *
     *   - a permission the caller does not hold. Their editor never rendered the
     *     checkbox (grantableTreeFor marks it ungrantable), so a submitted list
     *     that omits it is not a decision to remove it. Treating the omission as
     *     a removal would let a Manager quietly strip a Supervisor of everything
     *     the Manager cannot see.
     *   - a permission on a module the platform has switched off. That gate is
     *     EzBizi's, not the tenant's (§13). If a temporary kill switch cost every
     *     role its grants, the module would come back on to an organization whose
     *     roles had been silently downgraded, with nothing to restore from.
     *
     * @return string[]
     */
    public function preservedFor(Role $role, User $actor): array
    {
        $held = $this->heldBy($actor);

        return array_values(array_filter(
            $role->permissionKeys(),
            fn (string $key) => ! in_array($key, $held, true),
        ));
    }
}
