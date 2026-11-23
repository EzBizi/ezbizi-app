<?php

namespace App\Support\ViewAs;

use App\Models\Assignment;
use App\Models\Role;
use App\Models\User;
use App\Models\ViewAsSession;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The user a request runs as while "View as" is on (Jose, 2026-11-23).
 *
 * Two shapes:
 *
 *   person  the target's own row, wrapped. Saves write the target's row: the
 *           developer is acting as them.
 *   role    the ACTOR's row with role_id swapped for the chosen role and an
 *           in-memory `assignments` relation holding one assignment at the
 *           chosen place. Every reach question on User (canActOnFranchise,
 *           canActOnCompany, canActOnOrganization, reachableFranchises,
 *           reachableCompanies) reads `$this->assignments`, so they all answer
 *           for that place without a row being written. Never saved.
 *
 * NEVER WIDER THAN THE ACTOR: every permission answer is the target's AND the
 * actor's (hasPermission, roleAllows here; PermissionCatalogue::heldBy).
 */
class ViewAsUser extends User
{
    public User $viewAsActor;

    public ViewAsSession $viewAsSession;

    public bool $viewAsRoleClone = false;

    protected $table = 'users';

    public static function asPerson(User $target, User $actor, ViewAsSession $session): self
    {
        $user = self::wrap($target, $actor, $session);
        foreach ($target->getRelations() as $name => $value) {
            $user->setRelation($name, $value);
        }

        return $user;
    }

    public static function asRole(User $actor, Role $role, ?string $scopeType, ?string $scopeId, ViewAsSession $session): self
    {
        $user = self::wrap($actor, $actor, $session);
        $user->viewAsRoleClone = true;
        $user->setAttribute('role_id', $role->getKey());
        // The chosen place is the only one reached, so ViewScope::home() lands there.
        $preferences = $actor->preferences ?? [];
        unset($preferences['navigation.scope']);
        $user->setAttribute('preferences', $preferences);
        $user->syncOriginal();
        $user->setRelation('role', $role);
        $user->setRelation('organization', $actor->organization);

        $assignment = new Assignment([
            'organization_id' => $actor->organization_id,
            'user_id' => $actor->getKey(),
            'assigned_organization_id' => $scopeType === 'organization' ? $actor->organization_id : null,
            'assigned_company_id' => $scopeType === 'company' ? $scopeId : null,
            'assigned_franchise_id' => $scopeType === 'franchise' ? $scopeId : null,
        ]);
        $user->setRelation('assignments', $assignment->newCollection([$assignment]));

        return $user;
    }

    private static function wrap(User $source, User $actor, ViewAsSession $session): self
    {
        $user = new self;
        $user->setRawAttributes($source->getAttributes(), true);
        $user->exists = true;
        $user->setConnection($source->getConnectionName());
        $user->viewAsActor = $actor;
        $user->viewAsSession = $session;

        $token = $actor->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $user->withAccessToken($token);
        }

        return $user;
    }

    /** Relations name the users table's key as User does (user_id), not view_as_user_id. */
    public function getForeignKey()
    {
        return 'user_id';
    }

    public function getMorphClass()
    {
        return (new User)->getMorphClass();
    }

    // ── never wider than the actor ───────────────────────────────

    public function hasPermission(string $key): bool
    {
        return parent::hasPermission($key) && $this->viewAsActor->hasPermission($key);
    }

    public function roleAllows(string $key): bool
    {
        return parent::roleAllows($key) && $this->viewAsActor->roleAllows($key);
    }

    public function isAdministrator(): bool
    {
        return parent::isAdministrator() && $this->viewAsActor->isAdministrator();
    }

    public const NO_OWN_ROWS = '00000000-0000-0000-0000-000000000000';

    /** A role clone owns no per-user rows: the actor's grants are not the role's. */
    public function ownRowsKey(): string
    {
        return $this->viewAsRoleClone ? self::NO_OWN_ROWS : parent::ownRowsKey();
    }

    /** A role has no inbox given by name; the actor's own grants are not the role's. */
    public function personalGrantKeys(): array
    {
        return $this->viewAsRoleClone ? [] : parent::personalGrantKeys();
    }

    // ── a role clone is never written ────────────────────────────

    public function save(array $options = [])
    {
        return $this->viewAsRoleClone ? true : parent::save($options);
    }

    public function delete()
    {
        return $this->viewAsRoleClone ? false : parent::delete();
    }

    public function refresh()
    {
        return $this->viewAsRoleClone ? $this : parent::refresh();
    }

    public function fresh($with = [])
    {
        if ($this->viewAsRoleClone) {
            return $this;
        }
        $fresh = parent::fresh($with);

        return $fresh === null ? null : self::asPerson($fresh, $this->viewAsActor, $this->viewAsSession);
    }

    /** Queries started from this instance hydrate plain users, never more clones. */
    public function newInstance($attributes = [], $exists = false)
    {
        return (new User)->newInstance($attributes, $exists);
    }

    /** {kind, id, label} for audit metadata and /me. */
    public function viewingAs(): array
    {
        return $this->viewAsRoleClone
            ? ['kind' => 'role', 'id' => $this->role?->getKey(), 'label' => ViewAs::label($this->viewAsSession)]
            : ['kind' => 'person', 'id' => $this->getKey(), 'label' => ViewAs::label($this->viewAsSession)];
    }
}
