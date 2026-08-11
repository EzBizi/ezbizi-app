<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\ScopeReach;
use Illuminate\Auth\Access\Response;

/**
 * Who may look at, and change, the people in an organization (§4).
 *
 * ── What this policy is NOT for ──────────────────────────────────
 *
 * Nothing under `/me` goes through here. Those routes are bound to the
 * signed-in user's own record — their profile, their preferences, their second
 * factor — and there is nobody else to authorize against, which is why
 * routes/api.php says so at each of them. This policy is about OTHER people:
 * the Human Resources module's Records tab and the two things it can change.
 *
 * ── Reach is part of the question, and belongs here ──────────────
 *
 * §4's rule cuts both ways: "he should only be able to see same level or
 * downwards". Somebody assigned to one franchise has no business reading — let
 * alone deactivating — a person who works for a different company under the same
 * organization. So both methods below ask ScopeReach, and the refusal is a 403
 * because the TARGET is out of bounds, not the values (the split RolesController
 * sets out).
 *
 * A person is reachable when the caller's authority covers ANY of the places
 * that person is assigned to. Any rather than all, because §4 lets somebody hold
 * several assignments — Argentina and Mexico but not France — and a manager who
 * runs Argentina must be able to see the person working in it. What they cannot
 * do is discover the France half by reading the record, so the presenter only
 * shows the assignments the caller can reach.
 */
class UserPolicy
{
    public function __construct(
        private readonly ScopeReach $reach,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hr.view');
    }

    public function view(User $user, User $person): bool
    {
        return $user->hasPermission('hr.view')
            && $this->withinReach($user, $person);
    }

    /**
     * The assignment picker — roles and places this caller may hand out.
     *
     * Either permission, because two different forms need the same list: the
     * invitation form (`hr.create`) and the one that changes an existing
     * person's role (`hr.edit`). Gating it on `hr.create` alone would leave a
     * role that may edit people unable to see which roles it may give them,
     * which is a screen with an empty dropdown and no explanation.
     */
    public function assignOptions(User $user): bool
    {
        return $user->hasPermission('hr.create') || $user->hasPermission('hr.edit');
    }

    /**
     * May this person's role and active state be changed at all?
     *
     * Only the two questions a policy can answer without seeing the payload:
     * does the caller hold `hr.edit`, and is the target inside their reach. The
     * three refusals that depend on WHAT is being changed live in
     * HrPeopleController::update, because each of them needs the new value —
     * switching off your own account, dropping the last administrator, and
     * handing out a role that reaches further than your own.
     *
     * Changing your own ROLE is deliberately allowed, here and there. It is
     * bounded by the escalation rule (PermissionCatalogue::assertRoleAssignable),
     * so the worst it can do is reduce your own access — recoverable by somebody
     * senior, and a legitimate thing to want.
     */
    public function update(User $user, User $person): Response
    {
        if (! $user->hasPermission('hr.edit') || ! $this->withinReach($user, $person)) {
            return Response::deny('You do not have permission to change this person’s account.');
        }

        return Response::allow();
    }

    /**
     * Every place this person is assigned, asked against the caller's authority.
     *
     * You can always reach yourself: a person's own row appears in their own
     * list whatever their assignments say, because a Records tab that does not
     * contain the person reading it looks broken.
     *
     * Somebody with NO assignment at all is visible only to a caller who reaches
     * the whole organization. That state should not arise — every path that
     * creates a user creates an assignment in the same transaction — but if it
     * ever does, there is no smaller place to check them against, and falling
     * back to "everybody can see them" would be the wrong direction to fail in.
     */
    private function withinReach(User $user, User $person): bool
    {
        if ($person->is($user)) {
            return true;
        }

        if ($person->organization_id !== $user->organization_id) {
            return false;
        }

        if ($person->assignments->isEmpty()) {
            return $user->canActOnOrganization($user->organization);
        }

        return $person->assignments->contains(
            fn ($assignment) => $this->reach->coversAssignment($user, $assignment)
        );
    }
}
