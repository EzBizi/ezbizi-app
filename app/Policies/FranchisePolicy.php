<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;

/**
 * Who may add and change the places work happens (plan §3, §4).
 *
 * Creating one is authorised against the COMPANY it will belong to, not against
 * any existing franchise. That distinction matters for the case that actually
 * happens: a company created a moment ago has no franchise yet, so a check
 * phrased in terms of reachable franchises would make the first one
 * impossible to add.
 */
class FranchisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('franchises.view');
    }

    public function view(User $user, Franchise $franchise): bool
    {
        return $user->hasPermission('franchises.view')
            && $user->canActOnFranchise($franchise);
    }

    /** @param  Company|null  $company  the company it would belong to. */
    public function create(User $user, ?Company $company = null): bool
    {
        if (! $user->hasPermission('franchises.create')) {
            return false;
        }

        // "Can you open the form at all" — asked before a company is picked.
        if ($company === null) {
            return $user->reachableCompanies()->isNotEmpty();
        }

        return $user->canActOnCompany($company);
    }

    public function update(User $user, Franchise $franchise): bool
    {
        return $user->hasPermission('franchises.edit')
            && $user->canActOnFranchise($franchise);
    }

    public function deactivate(User $user, Franchise $franchise): bool
    {
        return $user->hasPermission('franchises.delete')
            && $user->canActOnFranchise($franchise);
    }
}
