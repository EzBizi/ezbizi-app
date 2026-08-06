<?php

namespace App\Models\Concerns;

use App\Models\Assignment;
use App\Models\Company;

/**
 * Whether a user's authority covers a COMPANY (plan §4).
 *
 * `canActOnFranchise` answers the same question one tier down and has existed
 * since the foundation, because until now every write in the app landed on a
 * franchise. Managing the structure itself is the first thing that acts on a
 * company directly, and asking "can you reach any of its franchises" would give
 * the wrong answer twice:
 *
 *   - a franchise-level manager could rename the company above them, because
 *     they can reach one of its franchises
 *   - nobody could add the FIRST franchise to a company that has none, because
 *     there is no franchise to reach yet
 *
 * §4's rule is that assignment does not cascade but AUTHORITY does, downwards
 * through the subtree. So this walks the other way: the assignment must sit at
 * or above the company.
 */
trait ReachesCompanies
{
    public function canActOnCompany(Company $company): bool
    {
        if (! $this->is_active || $company->organization_id !== $this->organization_id) {
            return false;
        }

        foreach ($this->assignments as $assignment) {
            $reachable = match ($assignment->scopeType()) {
                // The whole tenant, so every company in it.
                Assignment::SCOPE_ORGANIZATION =>
                    $assignment->assigned_organization_id === $company->organization_id,

                // The company itself, or its Sub Companies — authority runs
                // down the subtree. A Sub Company's manager does NOT reach the
                // parent, which is the direction that has to stay closed.
                Assignment::SCOPE_COMPANY =>
                    in_array(
                        $company->getKey(),
                        $assignment->assignedCompany?->subtreeCompanyIds() ?? [],
                        strict: true,
                    ),

                // A franchise assignment is beneath a company, so it confers
                // nothing over the company that contains it.
                Assignment::SCOPE_FRANCHISE => false,
            };

            if ($reachable) {
                return true;
            }
        }

        return false;
    }

    /** Every company this user may act on, parents and Sub Companies alike. */
    public function reachableCompanies(): \Illuminate\Support\Collection
    {
        return Company::query()
            ->where('organization_id', $this->organization_id)
            ->get()
            ->filter(fn (Company $c) => $this->canActOnCompany($c))
            ->values();
    }
}
