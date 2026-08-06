<?php

namespace App\Policies;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;

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
 */
class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('jobs.view');
    }

    public function view(User $user, Job $job): bool
    {
        return $user->hasPermission('jobs.view')
            && $job->organization_id === $user->organization_id
            && $user->canActOnFranchise($job->franchise);
    }

    /** @param  Franchise|null  $franchise  where the job would happen. */
    public function create(User $user, ?Franchise $franchise = null): bool
    {
        if (! $user->hasPermission('jobs.create')) {
            return false;
        }

        if ($franchise === null) {
            return $user->reachableFranchises()->isNotEmpty();
        }

        return $user->canActOnFranchise($franchise);
    }

    public function update(User $user, Job $job): bool
    {
        return $user->hasPermission('jobs.edit')
            && $job->organization_id === $user->organization_id
            && $user->canActOnFranchise($job->franchise);
    }

    public function delete(User $user, Job $job): bool
    {
        return $user->hasPermission('jobs.delete')
            && $job->organization_id === $user->organization_id
            && $user->canActOnFranchise($job->franchise);
    }
}
