<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Who may shape the organization (plan §3, §4).
 *
 * ── Why creating a company is an organization-level act ──────────
 *
 * A top-level Company sits directly under the Organization, so authorising it
 * against anything smaller would let somebody with one branch's assignment add
 * a sibling to the whole business. There is nothing above a top-level company
 * to check except the organization itself.
 *
 * A Sub Company is different: it hangs off a named parent, so the question is
 * whether the caller has authority over THAT company. §4's rule — assignment
 * does not cascade, authority does — makes this the same subtree walk used
 * everywhere else, just asked about a company rather than a franchise.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasPermission('companies.view')
            && $company->organization_id === $user->organization_id;
    }

    /**
     * @param  Company|null  $parent  the company this would sit under, if any.
     */
    public function create(User $user, ?Company $parent = null): bool
    {
        if (! $user->hasPermission('companies.create')) {
            return false;
        }

        // A Sub Company: authority over its parent is what is being exercised.
        if ($parent !== null) {
            return $user->canActOnCompany($parent);
        }

        // A top-level company. Only somebody whose authority already covers the
        // whole organization can add another branch to it — and reaching every
        // company that happens to exist today is NOT the same thing, because the
        // new one is not among them.
        return $user->assignments->contains(
            fn ($a) => $a->assigned_organization_id === $user->organization_id
        );
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasPermission('companies.edit')
            && $user->canActOnCompany($company);
    }

    /**
     * Deactivation, not deletion — see CompaniesController for why there is no
     * delete at all. Kept separate from `update` because switching a whole
     * branch of the business off is a different decision from renaming it, and
     * an organization may want to grant one without the other later.
     */
    public function deactivate(User $user, Company $company): bool
    {
        return $user->hasPermission('companies.delete')
            && $user->canActOnCompany($company);
    }
}
