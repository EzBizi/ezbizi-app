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
    public function actionLabel(string $action, ?ModuleDefinition $module = null): string
    {
        /*
         * A TAB IS CALLED WHAT IT IS CALLED ON SCREEN.
         *
         * Without this, `ucfirst` turns the action `hours.view` into the words
         * "Hours.view" on a checkbox, which is how `security.reset` has been
         * presented to administrators as "Security.reset" since HR shipped.
         * Dotted actions were rare enough for that to be a wart; tabs make them
         * the common case, so the wart becomes the whole screen.
         *
         * The label comes from the module's own TabDefinition, which is the
         * same string the screen's tab bar draws. Ticking "Work Hours" and then
         * looking for a tab with another name on it is a failure with no error
         * attached to it.
         */
        foreach ($module?->tabs ?? [] as $tab) {
            if ($tab->action() === $action) {
                return $tab->label;
            }
        }

        return $module?->actionLabels[$action] ?? ucfirst(str_replace('_', ' ', $action));
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
        if (! $user->is_active || $user->awaitingOnboardingApproval()) {
            return [];
        }

        $keys = $user->isAdministrator()
            ? $this->keys()
            : array_values(array_intersect($this->keys(), array_unique([
                ...($user->role?->permissionKeys() ?? []),
                // An inbox given to them by name opens Email for them (User::personalGrantKeys).
                ...$user->personalGrantKeys(),
            ])));

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
                // Where it is drawn, not who may reach it — see
                // ModuleDefinition::$parent. The client needs it to know that
                // Leads is a tab inside Marketing rather than a destination.
                'parent'    => $module->parent,
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

        $modules = [];

        foreach ($this->registry->all() as $module) {
            if ($module->rolesGroup === ModuleDefinition::ROLES_OMIT) {
                continue;
            }

            $available = $this->availability->isAvailable($module->key, $user->organization);
            $group = ModuleDefinition::rolesGroupMeta($module->rolesGroup);

            $modules[] = [
                'key'       => $module->key,
                'label'     => $module->label,
                // The editor nests a child under its parent's heading, so the
                // grants read in the same shape as the app somebody is granting
                // them for. It stays one switch per module either way: a parent
                // grants nothing about its children.
                'parent'    => $module->parent,
                'available' => $available,
                'group'     => $group ?? [
                    'key'   => ModuleDefinition::ROLES_TAB,
                    'label' => $module->label,
                    'hint'  => null,
                ],
                'actions'   => array_map(fn (string $action) => [
                    'key'       => "{$module->key}.{$action}",
                    'action'    => $action,
                    'label'     => $this->actionLabel($action, $module),
                    // Whether this action opens a TAB inside the module rather
                    // than permitting an act within it. The editor draws the two
                    // under separate headings, because "Work Hours" and "Edit"
                    // answer different questions and a flat list of eleven
                    // checkboxes mixing them is unreadable.
                    //
                    // Derived from the registry rather than sniffed from the
                    // key's shape: `security.reset` is also a dotted action and
                    // is NOT a tab, so a client counting dots would draw it
                    // under the wrong heading.
                    'tab'       => $module->tabs !== []
                        && in_array($action, array_map(fn ($t) => $t->action(), $module->tabs), true),
                    'grantable' => in_array("{$module->key}.{$action}", $held, true),
                    // Empty for almost everything. Where it is not, the editor
                    // has something to say before the save is refused — see
                    // ModuleDefinition::$actionRequires for why refusing beats
                    // quietly adding the missing key.
                    'requires'  => array_values($module->actionRequires[$action] ?? []),
                    'description' => $module->actionDescriptions[$action] ?? null,
                ], $module->actions),
            ];
        }

        usort($modules, function (array $a, array $b) {
            $rank = static fn (array $m) => match ($m['group']['key']) {
                ModuleDefinition::ROLES_TAB => 0,
                ModuleDefinition::ROLES_ORGANIZATION => 1,
                ModuleDefinition::ROLES_SETTINGS => 2,
                default => 3,
            };

            $cmp = $rank($a) <=> $rank($b);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Tabs keep registry order (the sidebar). Nested grants keep
            // the order they were declared in, which usort is stable for.
            return 0;
        });

        return array_values($modules);
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
     * Every granted permission arrives with whatever it cannot work without.
     *
     * Declared per module as `actionRequires`; almost nothing declares any.
     * The one case this was built for: `training.assign` without
     * `training.monitor` is a role that can assign training and has no control
     * anywhere to do it with, because the assign button lives in the Dashboard
     * pane that `monitor` opens. That saved cleanly, and the person holding it
     * saw an ordinary screen with nothing on it.
     *
     * Refuses rather than repairing. `withImpliedView` adds a missing `.view`
     * silently and that is safe, because `.view` is the least a module can
     * grant. A requirement can point the other way — `monitor` discloses
     * everyone's scores — so adding it unasked would hand out more than was
     * ticked. Naming what is missing and stopping is the honest half.
     *
     * Checked against the normalised set, for the reason assertGrantable
     * documents at length: validating the list somebody SENT while storing a
     * different, larger one is how the last hole in this file happened.
     *
     * @param  string[]  $keys
     *
     * @throws ValidationException
     */
    public function assertRequirementsMet(array $keys): void
    {
        $granted = $this->withImpliedView($keys);
        $missing = [];

        foreach ($granted as $key) {
            $module = $this->registry->get($this->moduleOf($key));

            if ($module === null) {
                continue;
            }

            $action = str_contains($key, '.') ? substr(strstr($key, '.'), 1) : $key;

            foreach ($module->actionRequires[$action] ?? [] as $needed) {
                if (! in_array($needed, $granted, true)) {
                    $missing[] = $key.' needs '.$needed;
                }
            }
        }

        $missing = array_values(array_unique($missing));

        if ($missing !== []) {
            throw ValidationException::withMessages(['permissions' => sprintf(
                'This role is missing a permission another one depends on: %s. Tick the missing '
                .'one as well, or take the other off — granted alone it gives somebody a '
                .'permission with nothing to use it on.',
                implode('; ', $missing),
            )]);
        }
    }

    /**
     * What a role actually grants — the whole catalogue for Administrator.
     *
     * Administrator owns no `permission_role` rows at all, because
     * `Role::hasPermission()` answers true for it unconditionally. So its
     * `permissionKeys()` is an empty array, and anything that reasons about
     * "what does this role hand out" from that list concludes: nothing.
     *
     * That is harmless where the list is only being displayed. It is a
     * privilege-escalation path the moment somebody is being GIVEN the role —
     * see assertRoleAssignable, which is the first caller and the reason this
     * exists.
     *
     * @return string[]
     */
    public function keysGrantedBy(Role $role): array
    {
        /*
         * Filtered by availability, exactly as heldBy() is — and the asymmetry
         * was a lockout, not a nuisance.
         *
         * This answered with the raw registry while heldBy() answers with the
         * registry MINUS whatever §13 has switched off for the organization.
         * assertRoleAssignable diffs one against the other, so the difference
         * was non-empty the moment ANY module was unavailable, for keys the
         * actor genuinely holds.
         *
         * Reachable by the ordinary rollout path, not an edge case: `beta` makes
         * a module unavailable to every organization outside the pilot, and
         * `disabled` is the mid-incident kill switch. With `jobs` switched off,
         * a founding Administrator was refused on BOTH routes to a second
         * administrator — promote and invite — with "it reaches further than
         * yours does: jobs.create, jobs.delete, jobs.edit, jobs.view", keys they
         * hold as fully as anybody can. Combined with last-administrator
         * protection, which correctly refuses to let the only administrator be
         * demoted or switched off, that organization could never have a second
         * one: locked out by a switch its own staff cannot see, until EzBizi
         * turned the module back on.
         *
         * Both sides of the comparison now answer the same question about the
         * same organization.
         */
        return array_values(array_filter(
            $this->keysDeclaredBy($role),
            fn (string $key) => $this->availability->isPermissionAvailable(
                $key,
                $role->organization,
            ),
        ));
    }

    /**
     * The same list BEFORE §13 is applied — what the organization itself put on
     * this role.
     *
     * Split out of keysGrantedBy so the two can be compared, which is the only
     * way to ask whether the PLATFORM has taken something away rather than the
     * organization never having granted it. Those two produce an identical empty
     * list and mean opposite things: one is a role somebody deliberately created
     * with nothing on it, the other is a role whose every module has been
     * switched off underneath it.
     *
     * Intersected with the registry for the same reason heldBy() is: a key left
     * in `permission_role` by a module that no longer exists grants nothing, and
     * must not count as something the role declares.
     *
     * @return string[]
     */
    public function keysDeclaredBy(Role $role): array
    {
        return $role->isAdministrator()
            ? $this->keys()
            : array_values(array_intersect($this->keys(), $role->permissionKeys()));
    }

    /**
     * Has the platform left this role with nothing at all to grant? (§13)
     *
     * Asked at invitation acceptance, where the question is what the
     * ORGANIZATION can still offer somebody who has not joined yet. A role whose
     * every module has been switched off produces an account that signs in and
     * reaches nothing, with nothing on screen saying why — which is the exact
     * failure `invitations.role_id` exists to prevent, arriving a week late.
     *
     * ── Why this is not "every permission must still be available" ──
     *
     * That stricter reading was considered and rejected, and the reason is
     * recorded because it is the version somebody will propose again.
     *
     * `beta` is the ordinary rollout state: a module is available to a pilot
     * list and unavailable to everyone else. Under the strict rule, every
     * outstanding invitation into any role that touches that module would be
     * refused at acceptance — for a switch the organization cannot see, did not
     * set and cannot undo. It is the same shape as the lockout recorded in
     * keysGrantedBy above.
     *
     * It would also be inconsistent with how the app treats everybody already
     * here. `heldBy()` filters an existing employee's permissions by the same
     * availability rule, so a switched-off module quietly costs them those keys
     * and nothing else — they are not signed out and their role is not taken
     * away. A new starter should land in the same place, not be turned away at
     * the door for it.
     *
     * A role that grants nothing because nobody put anything on it is left
     * alone: that is the organization's own choice, it was as true when the
     * invitation was sent as it is now, and refusing it would break a link that
     * was valid the day it was issued.
     */
    public function platformHasWithdrawnEverything(Role $role): bool
    {
        return $this->keysDeclaredBy($role) !== [] && $this->keysGrantedBy($role) === [];
    }

    /**
     * Refuse handing somebody a role that reaches further than the giver's own.
     *
     * ── The hole this closes ─────────────────────────────────────
     *
     * §4's escalation bound — "nobody can grant a permission they do not
     * themselves hold" — was written for the roles EDITOR, where the permissions
     * are visible on screen. Giving a person a role grants exactly the same
     * permissions without naming any of them, so a Manager with `hr.edit` and
     * nothing else could have invited somebody as Administrator and then signed
     * in as them. Same escalation, different door.
     *
     * Deliberately its own method rather than `assertGrantable($actor,
     * $role->permissionKeys())` for two reasons. Administrator's key list is
     * empty (see keysGrantedBy), so the naive call would have passed for the one
     * role that matters most. And the refusal belongs on `role_id` — the field
     * the person actually chose — rather than on `permissions`, which is not a
     * field on this form at all.
     *
     * @throws ValidationException
     */
    public function assertRoleAssignable(User $actor, Role $role): void
    {
        // A role given its own list offers exactly that list (Jose,
        // 2026-10-01: "select what roles they can offer").
        if (($list = $this->invitableBy($actor)) !== null) {
            if (in_array($role->getKey(), $list, true)) {
                return;
            }

            throw ValidationException::withMessages(['role_id' => sprintf(
                'Your role cannot offer the %s role. Which roles it can offer is set on the role, '
                .'in Roles & Permissions.',
                $role->name,
            )]);
        }

        $beyond = $this->beyondTheReachOf($actor, $role);

        if ($beyond === []) {
            return;
        }

        throw ValidationException::withMessages(['role_id' => sprintf(
            'You cannot give somebody the %s role, because it reaches further than yours does: '
            .'%s. Nobody can hand out more than they have.',
            $role->name,
            implode(', ', array_slice($beyond, 0, 5))
                .(count($beyond) > 5 ? sprintf(' and %d more', count($beyond) - 5) : ''),
        )]);
    }

    /**
     * The same question without the exception — for a picker that has to show
     * every role and mark the ones this caller cannot hand out.
     *
     * Shown-and-marked rather than filtered away, the same call `grantableTreeFor`
     * makes about actions: a role that simply vanishes from the list leaves
     * somebody unable to tell "we do not have that" from "I am not allowed to
     * give it".
     */
    public function mayAssignRole(User $actor, Role $role): bool
    {
        if (($list = $this->invitableBy($actor)) !== null) {
            return in_array($role->getKey(), $list, true);
        }

        return $this->beyondTheReachOf($actor, $role) === [];
    }

    /**
     * The roles the actor's role was given to offer, or null when it has no
     * list and the rule by reach applies. An administrator always has the
     * rule by reach, which lets them offer everything.
     *
     * @return list<string>|null
     */
    private function invitableBy(User $actor): ?array
    {
        $role = $actor->role;

        if ($role === null || $role->isAdministrator() || ! is_array($role->invitable_role_ids)) {
            return null;
        }

        return array_values(array_map('strval', $role->invitable_role_ids));
    }

    /**
     * Whether the editor may put this role on another role's list: only a role
     * they could offer themselves under the rule by reach, so the list never
     * becomes a way past "nobody can hand out more than they have".
     */
    public function mayListAsInvitable(User $editor, Role $role): bool
    {
        return $editor->role?->isAdministrator() || $this->beyondTheReachOf($editor, $role) === [];
    }

    /** @return string[] the keys this role grants that the actor does not hold */
    private function beyondTheReachOf(User $actor, Role $role): array
    {
        return array_values(array_diff($this->keysGrantedBy($role), $this->heldBy($actor)));
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
