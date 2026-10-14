<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Guideline;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and write Guidelines. Reading needs the Guidelines tab
 * (`guidelines.guidelines.view`); writing needs `guidelines.manage`. Past the
 * permission it is AssistantEntryPolicy's ownership, verb for verb.
 */
class GuidelinePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('guidelines.guidelines.view')
            ? Response::allow()
            : Refusal::permission('see the assistant\'s answers');
    }

    public function view(User $user, Guideline $guideline): Response
    {
        if (! $user->hasPermission('guidelines.guidelines.view')) {
            return Refusal::permission('see the assistant\'s answers');
        }

        return $this->within($user, $guideline, 'see this guideline');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('guidelines.manage')) {
            return Refusal::permission('add a guideline');
        }

        if ($company === null) {
            return $this->createOnOrganization($user);
        }

        if ($franchise !== null) {
            if ($franchise->company_id !== $company->getKey()) {
                return Response::deny('That place is not part of this company.');
            }

            return $user->canActOnFranchise($franchise)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a guideline', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a guideline', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('guidelines.manage')) {
            return Refusal::permission('add a guideline');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_ORGANIZATION, 'add a guideline', $organization->name);
    }

    public function update(User $user, Guideline $guideline): Response
    {
        if (! $user->hasPermission('guidelines.manage')) {
            return Refusal::permission('change a guideline');
        }

        return $this->within($user, $guideline, 'change this guideline', write: true);
    }

    public function delete(User $user, Guideline $guideline): Response
    {
        if (! $user->hasPermission('guidelines.manage')) {
            return Refusal::permission('delete a guideline');
        }

        return $this->within($user, $guideline, 'delete this guideline', write: true);
    }

    private function within(User $user, Guideline $guideline, string $act, bool $write = false): Response
    {
        if ($guideline->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $guideline->organization_id,
                $guideline->franchise_id !== null ? 'franchise' : ($guideline->company_id !== null ? 'company' : 'organization'),
                $guideline->franchise_id ?? $guideline->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this guideline from your current view or assignments.');
        }

        if ($guideline->company_id === null) {
            return Response::allow();
        }

        $company = Company::find($guideline->company_id);

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach($user, Refusal::AT_COMPANY, $act, $company?->name ?? 'that company');
        }

        if ($guideline->franchise_id === null) {
            return Response::allow();
        }

        $franchise = Franchise::find($guideline->franchise_id);

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /** Franchise assignments see their company's guidelines, as with guides. */
    private function canSeeCompany(User $user, Company $company): bool
    {
        return $user->canActOnCompany($company)
            || $user->reachableFranchises()->contains(fn (Franchise $f) => $f->company_id === $company->getKey());
    }
}
