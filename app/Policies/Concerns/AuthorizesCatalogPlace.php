<?php

namespace App\Policies\Concerns;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Reach for a row that sits on organization / company / franchise.
 *
 * Services, groups, labels, inventory and training all file the same way;
 * each policy names its nouns and permissions. The protected defaults below
 * are where a module words or loads something differently.
 */
trait AuthorizesCatalogPlace
{
    abstract protected function permission(string $action): string;

    abstract protected function addAct(): string;

    abstract protected function seeAct(): string;

    abstract protected function changeAct(): string;

    abstract protected function deleteAct(): string;

    /** The refusal for lacking the read permission at all. */
    protected function seeAnyAct(): string
    {
        return $this->seeAct();
    }

    protected function companyOf(Model $row): ?Company
    {
        return $row->company;
    }

    protected function franchiseOf(Model $row): ?Franchise
    {
        return $row->franchise;
    }

    public function viewAny(User $user): Response
    {
        return $user->hasPermission($this->permission('view'))
            ? Response::allow()
            : Refusal::permission($this->seeAnyAct());
    }

    public function view(User $user, Model $row): Response
    {
        if (! $user->hasPermission($this->permission('view'))) {
            return Refusal::permission($this->seeAnyAct());
        }

        return $this->within($user, $row, $this->seeAct());
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission($this->permission('create'))) {
            return Refusal::permission($this->addAct());
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, $this->addAct(), $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, $this->addAct(), $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission($this->permission('create'))) {
            return Refusal::permission($this->addAct());
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                $this->addAct(),
                $organization->name,
            );
    }

    public function update(User $user, Model $row): Response
    {
        if (! $user->hasPermission($this->permission('edit'))) {
            return Refusal::permission($this->changeAct());
        }

        return $this->within($user, $row, $this->changeAct(), write: true);
    }

    public function delete(User $user, Model $row): Response
    {
        if (! $user->hasPermission($this->permission('delete'))) {
            return Refusal::permission($this->deleteAct());
        }

        return $this->within($user, $row, $this->deleteAct(), write: true);
    }

    private function within(User $user, Model $row, string $act, bool $write = false): Response
    {
        if ($row->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $row->organization_id,
                $row->franchise_id !== null ? 'franchise' : ($row->company_id !== null ? 'company' : 'organization'),
                $row->franchise_id ?? $row->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this record from your current view or assignments.');
        }
        if ($row->company_id === null) {
            return Response::allow();
        }

        $company = $this->companyOf($row);

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($row->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $this->franchiseOf($row);

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading — franchise assignments see their
     * company's General even though they cannot `canActOnCompany`.
     */
    private function canSeeCompany(User $user, Company $company): bool
    {
        if ($user->canActOnCompany($company)) {
            return true;
        }

        return $user->reachableFranchises()->contains(
            fn (Franchise $f) => $f->company_id === $company->getKey(),
        );
    }
}
