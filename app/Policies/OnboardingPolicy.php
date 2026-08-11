<?php

namespace App\Policies;

use App\Models\Onboarding;
use App\Models\User;
use App\Support\Access\ScopeReach;
use Illuminate\Auth\Access\Response;

/**
 * Who may run an onboarding, and who may fill one in
 * (§4, EZBIZI-HR-PLAN.md, "Who may do what").
 *
 * ── The employee is not a permission holder, and must not need to be ──
 *
 * Every other policy in this application answers one question: does this person
 * hold the permission, and does their reach cover the record. That is the wrong
 * shape here, and getting it wrong would break the feature outright — a new
 * starter holds no HR permission at all. They cannot see the people list, the
 * invitations or the templates, and they should not. What they can do is fill in
 * their own form.
 *
 * So every ability below has two doors:
 *
 *   the SUBJECT   the person this onboarding is about, identified by
 *                 `onboardings.user_id`. No permission, no reach — it is their
 *                 own paperwork, and "may I read my own contract" is a question
 *                 that has to answer yes.
 *   the EMPLOYER  somebody with the HR permission whose §4 reach covers where
 *                 this person is being placed.
 *
 * Which door somebody came through then decides which FIELDS they may write —
 * see App\Services\Hr\OnboardingForm. This policy answers whether they may touch
 * the onboarding at all; it deliberately does not answer `filled_by`, because
 * that is per field and per row and a policy cannot see either.
 *
 * ── Reach comes from the placement, not from the record ──────────
 *
 * An onboarding has no franchise column of its own. Its place is the invitation's
 * assigned scope, or — once the account exists — the person's assignment. See
 * Onboarding::scope() for why it is derived rather than copied: a third home for
 * "where does this person work" is a third thing to keep in step.
 */
class OnboardingPolicy
{
    public function __construct(
        private readonly ScopeReach $reach,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hr.view');
    }

    public function view(User $user, Onboarding $onboarding): bool
    {
        return $this->isSubject($user, $onboarding)
            || $this->employerMay($user, $onboarding, 'hr.view');
    }

    /** Starting one is the same authority as inviting somebody. */
    public function create(User $user): bool
    {
        return $user->hasPermission('hr.create');
    }

    /**
     * Saving answers, and attaching a file to this record.
     *
     * `hr.edit` for the employer rather than `hr.create`, because filling in
     * somebody's contract details is the same kind of act as changing their role
     * — it writes to a person's record — while `hr.create` is about bringing
     * somebody in. The subject needs neither.
     *
     * AttachmentsController authorizes `update` on the subject of an upload, so
     * this is also what lets an employee attach the scan of their licence to
     * their own onboarding and nobody else's.
     */
    public function update(User $user, Onboarding $onboarding): Response
    {
        if ($this->isSubject($user, $onboarding)) {
            return Response::allow();
        }

        return $this->employerResponse($user, $onboarding, 'hr.edit', 'fill in this onboarding');
    }

    /**
     * Seeing a sensitive answer in the clear.
     *
     * The same authority as writing, and deliberately not the same as reading
     * the tab. `hr.view` opens the screen; the values on it are masked for
     * everybody, and asking for one in the clear is a separate act that gets
     * written down against the person who asked.
     *
     * The subject always may. The plan leaves open whether an employee may
     * CHANGE their own bank account without an administrator seeing it; whether
     * they may LOOK at it is not open, and a mechanism that made somebody ask
     * permission to read their own details back would be indefensible.
     */
    public function reveal(User $user, Onboarding $onboarding): bool
    {
        return $this->isSubject($user, $onboarding)
            || $this->employerMay($user, $onboarding, 'hr.edit');
    }

    /**
     * Correcting a completed onboarding.
     *
     * Both sides may, which follows the plan's recommendation on the one
     * question it leaves open: "Whether an employee may change their own bank
     * account without an administrator seeing it. Payroll fraud looks exactly
     * like that; the recommendation is to allow it, audit it and notify."
     *
     * So it is allowed, it is audited, and the other side is notified — the
     * three together are what make it safe rather than the refusal. If the owner
     * decides the other way, this method is the one line that changes, and every
     * amendment made in the meantime is on the record with its author.
     */
    public function amend(User $user, Onboarding $onboarding): Response
    {
        if ($this->isSubject($user, $onboarding)) {
            return Response::allow();
        }

        return $this->employerResponse($user, $onboarding, 'hr.edit', 'amend this contract');
    }

    /**
     * Is this person acting for the employer on this onboarding?
     *
     * Asked by the controller to work out which side somebody is on, which is
     * not the same question as whether they may act at all — somebody can be the
     * subject and nothing else, and then the answer here is no while `view` and
     * `update` are both yes.
     */
    public function actAsEmployer(User $user, Onboarding $onboarding): bool
    {
        return $this->employerMay($user, $onboarding, 'hr.edit');
    }

    // ── shared ───────────────────────────────────────────────────

    private function isSubject(User $user, Onboarding $onboarding): bool
    {
        return $onboarding->user_id !== null
            && $onboarding->user_id === $user->getKey()
            && $onboarding->organization_id === $user->organization_id;
    }

    private function employerMay(User $user, Onboarding $onboarding, string $permission): bool
    {
        if ($onboarding->organization_id !== $user->organization_id
            || ! $user->hasPermission($permission)) {
            return false;
        }

        $scope = $onboarding->scope();

        return $this->reach->covers($user, $scope['type'], $scope['id']);
    }

    private function employerResponse(
        User $user,
        Onboarding $onboarding,
        string $permission,
        string $verb,
    ): Response {
        if ($onboarding->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission($permission)) {
            return Response::deny("You do not have permission to {$verb}.");
        }

        $scope = $onboarding->scope();

        if (! $this->reach->covers($user, $scope['type'], $scope['id'])) {
            return Response::deny(sprintf(
                'That onboarding is for somebody being placed outside your reach, so it is '
                .'not yours to %s. Somebody with authority over that %s can.',
                $verb, $scope['type'],
            ));
        }

        return Response::allow();
    }
}
