<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may add and change the places work happens (plan §3, §4).
 *
 * Creating one is authorised against the COMPANY it will belong to, not against
 * any existing franchise. That distinction matters for the case that actually
 * happens: a company created a moment ago has no franchise yet, so a check
 * phrased in terms of reachable franchises would make the first one
 * impossible to add.
 *
 * ── Why the refusals name the company rather than the place ──────
 *
 * Because that is where the answer is. "Add a place" refused against a company
 * has nothing to do with the place — it does not exist yet — and telling
 * somebody they cannot reach a franchise they were about to create is the kind
 * of sentence that sends them looking for a setting that is not there.
 */
class FranchisePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('franchises.view')
            ? Response::allow()
            : Refusal::permission($this->seeing($user));
    }

    public function view(User $user, Franchise $franchise): Response
    {
        if (! $user->hasPermission('franchises.view')) {
            return Refusal::permission($this->seeing($user));
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach(
                $user, Refusal::AT_FRANCHISE, 'see '.$franchise->name, $franchise->name,
            );
    }

    /** @param  Company|null  $company  the company it would belong to. */
    public function create(User $user, ?Company $company = null): Response
    {
        if (! $user->hasPermission('franchises.create')) {
            return Refusal::permission('add a place');
        }

        // "Can you open the form at all", before a company is picked. Nothing
        // asks it today — the organization tree names the company on every
        // control that leads here, so FranchisesController always has one. Kept
        // for the same reason as the twin branch in JobPolicy::create: the
        // class-only form of the question is Laravel's convention, and letting
        // it fall through to `true` would be wrong for somebody who reaches no
        // company at all.
        if ($company === null) {
            return $user->reachableCompanies()->isNotEmpty()
                ? Response::allow()
                : Refusal::nowhereToAct($user, Refusal::AT_COMPANY, 'add a place');
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach(
                $user, Refusal::AT_COMPANY, 'add a place to '.$company->name, $company->name,
            );
    }

    public function update(User $user, Franchise $franchise): Response
    {
        if (! $user->hasPermission('franchises.edit')) {
            return Refusal::permission('change a place');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach(
                $user, Refusal::AT_FRANCHISE, 'change '.$franchise->name, $franchise->name,
            );
    }

    public function deactivate(User $user, Franchise $franchise): Response
    {
        if (! $user->hasPermission('franchises.delete')) {
            return Refusal::permission('switch a place off');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach(
                $user, Refusal::AT_FRANCHISE, 'switch '.$franchise->name.' off', $franchise->name,
            );
    }

    /**
     * "see franchises", in whatever this organization calls them (§8).
     *
     * The word is renamed per tenant, so a refusal that hardcoded it would be
     * the one sentence in the product still saying "franchise" to a business
     * that calls them territories.
     */
    private function seeing(User $user): string
    {
        $label = strtolower($user->organization?->franchise_label ?? 'franchise');

        return "see the {$label}s";
    }
}
