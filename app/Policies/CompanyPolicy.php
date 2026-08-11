<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

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
 *
 * ── The refusal here is the one people most need in words ────────
 *
 * Because it is the only one in the system refused by POSITION rather than by
 * place. Somebody holding `companies.create` at a company, looking at a dead
 * "Add company" button, has the permission the button is named after and will
 * reasonably conclude the app is broken. "Authority never flows upwards" is a
 * decision (EZBIZI-TODO #12), not an accident, so it says so.
 */
class CompanyPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('companies.view')
            ? Response::allow()
            : Refusal::permission('see the shape of the organization');
    }

    public function view(User $user, Company $company): Response
    {
        if ($company->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission('companies.view')
            ? Response::allow()
            : Refusal::permission('see the shape of the organization');
    }

    /**
     * @param  Company|null  $parent  the company this would sit under, if any.
     */
    public function create(User $user, ?Company $parent = null): Response
    {
        if (! $user->hasPermission('companies.create')) {
            return Refusal::permission($parent === null ? 'add a company' : 'add a sub company');
        }

        // A Sub Company: authority over its parent is what is being exercised.
        if ($parent !== null) {
            return $user->canActOnCompany($parent)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a sub company', $parent->name);
        }

        // A top-level company. Only somebody whose authority already covers the
        // whole organization can add another branch to it — and reaching every
        // company that happens to exist today is NOT the same thing, because the
        // new one is not among them.
        $standsAtTheTop = $user->assignments->contains(
            fn ($a) => $a->assigned_organization_id === $user->organization_id
        );

        return $standsAtTheTop
            ? Response::allow()
            : Refusal::abovePosition('add a company');
    }

    public function update(User $user, Company $company): Response
    {
        if (! $user->hasPermission('companies.edit')) {
            return Refusal::permission('change a company');
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach(
                $user, Refusal::AT_COMPANY, 'change '.$company->name, $company->name,
            );
    }

    /**
     * Deactivation, not deletion — see CompaniesController for why there is no
     * delete at all. Kept separate from `update` because switching a whole
     * branch of the business off is a different decision from renaming it, and
     * an organization may want to grant one without the other later.
     */
    public function deactivate(User $user, Company $company): Response
    {
        if (! $user->hasPermission('companies.delete')) {
            return Refusal::permission('switch a company off');
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                'switch '.$company->name.' off',
                $company->name,
                'Every place under it goes quiet with it, which is why it is asked '
                .'of somebody standing over the whole branch.'
            );
    }
}
