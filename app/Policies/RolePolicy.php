<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may compose the organization's roles (plan §4).
 *
 * ── Why Administrator is immutable, in every direction ───────────
 *
 * The owner's words: "it could lock itself out of its own powers, too
 * dangerous." Administrator holds everything implicitly — it owns no
 * `permission_role` rows at all — so "remove Delete from Administrator" is not a
 * smaller grant, it is an attempt to write an exception into a role whose whole
 * definition is that it has none. Renaming it used to be worse still: identity
 * matched on the display name, so a rename evaporated every permission at once
 * (see the 2026_08_10_010000 migration). That specific hole is closed, and the
 * role stays immutable anyway, because the failure mode it guards against is not
 * a bug in one function — it is an administrator removing the last thing that
 * would have let them undo it.
 *
 * Every refusal here carries a sentence. A bare 403 on a screen the person is
 * plainly allowed to be on reads as a broken product, and the next thing they do
 * is ask somebody to "fix the permissions" — which is the one change that cannot
 * help.
 *
 * ── What is NOT decided here ─────────────────────────────────────
 *
 * Whether a caller may grant a PARTICULAR permission is not a policy question —
 * it is bounded by what they themselves hold, per key, and belongs with the
 * catalogue that knows what "hold" means. See PermissionCatalogue::assertGrantable.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view')
            && $role->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): Response
    {
        // The ordinary refusal comes first, so somebody with no business on this
        // screen is not told which role is the special one.
        if ($role->organization_id !== $user->organization_id
            || ! $user->hasPermission('roles.edit')) {
            return Response::deny('You do not have permission to change roles.');
        }

        if ($role->isAdministrator()) {
            return Response::deny(
                'Administrator cannot be changed — not its name, and not its permissions. It '
                .'holds everything by definition, so editing it is the one change that could '
                .'leave an organization with nobody able to undo it. Build a role beside it '
                .'instead.'
            );
        }

        return Response::allow();
    }

    public function delete(User $user, Role $role): Response
    {
        if ($role->organization_id !== $user->organization_id
            || ! $user->hasPermission('roles.delete')) {
            return Response::deny('You do not have permission to delete roles.');
        }

        if ($role->isAdministrator()) {
            return Response::deny(
                'Administrator cannot be deleted. Every organization needs one role that can '
                .'reach everything, including this screen.'
            );
        }

        return Response::allow();
    }
}
