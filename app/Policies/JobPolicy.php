<?php

namespace App\Policies;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a job (plan §4, §5.2).
 *
 * Unlike customers, jobs are never shared, so every question here is asked twice:
 * WHAT may this user do (permission) and WHERE (reach). Both must pass. A
 * permission without reach is meaningless, and reach without permission is worse.
 *
 * Reach is subtree-based, not an exact scope match — a company-level user can act
 * on everything beneath that company including its Sub Companies' franchises
 * (§4). Getting that wrong in the restrictive direction leaves company managers
 * able to see work they cannot touch, which reads as a broken app rather than a
 * policy decision.
 *
 * ── Every refusal says which of the two failed ───────────────────
 *
 * That is the whole reason these return a [Response] rather than a bool. "You
 * cannot touch this job" has two completely different remedies — a permission
 * somebody adds to your role, or an assignment somebody widens — and a bare
 * false collapses them into "This action is unauthorized.", which points at
 * neither. The wording is in App\Support\Access\Refusal so all five policies
 * that had this problem answer the same way.
 */
class JobPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('jobs.view')
            ? Response::allow()
            : Refusal::permission('see jobs');
    }

    public function view(User $user, Job $job): Response
    {
        if (! $user->hasPermission('jobs.view')) {
            return Refusal::permission('see jobs');
        }

        return $this->within($user, $job, 'see this job');
    }

    /** @param  Franchise|null  $franchise  where the job would happen. */
    public function create(User $user, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('jobs.create')) {
            return Refusal::permission('add a job');
        }

        // "Can you open the form at all", asked before a place is picked.
        //
        // No caller passes null today: JobsController always knows the franchise
        // by the time it authorizes, and the client answers the same question
        // for itself from the reach and the permissions /me already gives it,
        // rather than spending a round trip on it. The branch stays because the
        // class-only form of this question is Laravel's own convention and
        // answering it `true` — which is what a missing branch would do — would
        // be wrong for somebody assigned nowhere.
        if ($franchise === null) {
            return $user->reachableFranchises()->isNotEmpty()
                ? Response::allow()
                : Refusal::nowhereToAct($user, Refusal::AT_FRANCHISE, 'add a job');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a job', $franchise->name);
    }

    public function update(User $user, Job $job): Response
    {
        if (! $user->hasPermission('jobs.edit')) {
            return Refusal::permission('change a job');
        }

        return $this->within($user, $job, 'change this job');
    }

    public function delete(User $user, Job $job): Response
    {
        if (! $user->hasPermission('jobs.delete')) {
            return Refusal::permission('delete a job');
        }

        return $this->within($user, $job, 'delete this job');
    }

    /**
     * The reach half, once.
     *
     * A job from another organization answers "no such job" rather than "not
     * allowed": the tenant scope means this is unreachable in practice, and
     * confirming that an id exists elsewhere on the platform would be an
     * existence oracle if it ever became reachable.
     */
    private function within(User $user, Job $job, string $act): Response
    {
        if ($job->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($job->franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $job->franchise->name);
    }
}
