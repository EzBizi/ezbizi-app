<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Modules\PermissionCatalogue;
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
        // Asked separately, and answered separately.
        //
        // The two used to share one sentence — "You do not have permission to
        // change this person's account" — for both outcomes, which is the exact
        // failure Refusal exists to stop: a branch manager who holds `hr.edit`
        // and asked about somebody in another company was told they lacked a
        // permission they were holding, and the remedy that sentence implies is
        // one nobody can give them, because nothing is missing from their role.
        if (! $user->hasPermission('hr.edit')) {
            return Refusal::permission('change this person’s account');
        }

        if (! $this->withinReach($user, $person)) {
            return $this->outOfBounds($user);
        }

        return Response::allow();
    }

    /**
     * Strip somebody's second factor, or hand them a password reset link.
     *
     * ── Why this is not `hr.edit` ────────────────────────────────
     *
     * Editing a person's role and clearing their authenticator are different
     * powers wearing the same job title. `hr.edit` is held today by anyone who
     * maintains the people list; if this reused it, deploying would silently
     * hand every one of them the ability to take the second factor off every
     * colleague in reach — a privilege escalation nobody asked for and nobody
     * would see happen.
     *
     * So it is its own key, ungranted until somebody grants it, on the same
     * reasoning that made `monitoring.control` separate from watching a screen.
     *
     * ── Never yourself ───────────────────────────────────────────
     *
     * Not because it would strand you — it would not; you would simply enrol
     * again. It is refused because "reset the second factor on the account I am
     * signed in to" is precisely what somebody who has stolen a session wants,
     * and it is the one use of this endpoint that never needs to exist: a person
     * who still has their own authenticator can rotate it from Your account, and
     * one who has lost it is asking a colleague anyway.
     */
    public function resetSecurity(User $user, User $person): Response
    {
        if (! $user->hasPermission('hr.security.reset')) {
            return Refusal::permission('reset this person’s sign-in security');
        }

        if ($user->is($person)) {
            return Response::deny(
                'This is for somebody else’s account. To change your own '
                .'authenticator, use Your account — that path keeps the current one '
                .'working until the replacement is confirmed, and this one does not.'
            );
        }

        if (! $this->withinReach($user, $person)) {
            return $this->outOfBounds($user);
        }

        return Response::allow();
    }

    /**
     * Erase somebody for good (Jose, 2026-10-20: "different than deactivation,
     * we are missing full deletion"). Its own key, for the reason
     * `security.reset` is; never yourself, which is Your account's own delete.
     */
    public function deletePermanently(User $user, User $person): Response
    {
        if (! $user->hasPermission('hr.delete')) {
            return Refusal::permission('delete people permanently');
        }

        if ($user->is($person)) {
            return Response::deny('This is for somebody else. To delete your own account, use Your account.');
        }

        if (! $this->withinReach($user, $person)) {
            return $this->outOfBounds($user);
        }

        // Nobody deletes a person whose role they could not hand out
        // themselves - an administrator above all (R&P, 2026-10-20).
        if ($person->role !== null
            && ! app(PermissionCatalogue::class)->mayAssignRole($user, $person->role)) {
            return Response::deny('Only somebody who could give them their role can delete them. An administrator can.');
        }

        return Response::allow();
    }

    /**
     * They hold `hr.edit` and this person is not theirs. Which of two reasons?
     *
     * ── Neither the person nor their place is ever named ─────────
     *
     * The one way this refusal differs from Refusal::noReach. noReach names the
     * franchise because the caller picked it and already knows it. Here the
     * out-of-reach thing is somebody ELSE, and the people list is reach-filtered
     * — so this caller has never been shown that name or that place, and a
     * helpful 403 would be the first thing to tell them. Same reasoning as the
     * presenter trimming the assignments it shows.
     *
     * ── But WHOSE assignment is missing is worth saying ──────────
     *
     * There was one sentence for both, and it told somebody holding no
     * assignment at all that "this person is assigned outside where you are" and
     * that "an administrator can widen your assignment". Both halves wrong for
     * them: there is no "where you are" for anybody to be outside of, and the
     * fix is a first assignment rather than a wider one. That is the same defect
     * Refusal::nowhereToAct exists to prevent one level up — the reach failures
     * that read alike and need different people to fix them.
     *
     * The other branch no longer claims to know where the person IS, only that
     * they are not inside this caller's part of the business. Somebody with no
     * assignment of their own is off-invariant rather than impossible (see
     * [withinReach]), and "assigned outside where you are" would be false about
     * them too.
     */
    private function outOfBounds(User $user): Response
    {
        if ($user->assignments->isEmpty()) {
            return Response::deny(
                'You hold the permission to change people, but you are not assigned '
                .'anywhere yet, so there is nobody else whose account is yours to change. '
                .'An administrator can assign you to a part of the business.'
            );
        }

        return Response::deny(
            'You hold the permission to change people, but this person is not inside the '
            .'part of the business you are assigned to, so their account is not yours to '
            .'change. Somebody with authority over them can — and an administrator can '
            .'widen your assignment.'
        );
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
