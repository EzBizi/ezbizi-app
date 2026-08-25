<?php

namespace App\Policies\Concerns;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Reach for a row that sits on organization / company / franchise.
 *
 * Copied from InventoryItemPolicy with the nouns parameterized. Services,
 * groups, and labels all file the same way.
 */
trait AuthorizesCatalogPlace
{
    abstract protected function permission(string $action): string;

    abstract protected function addAct(): string;

    abstract protected function seeAct(): string;

    abstract protected function changeAct(): string;

    abstract protected function deleteAct(): string;

    public function viewAny(User $user): Response
    {
        return $user->hasPermission($this->permission('view'))
            ? Response::allow()
            : Refusal::permission($this->seeAct());
    }

    public function view(User $user, Model $row): Response
    {
        if (! $user->hasPermission($this->permission('view'))) {
            return Refusal::permission($this->seeAct());
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

        if ($row->company_id === null) {
            if (! $write) {
                return Response::allow();
            }

            $organization = $user->organization;

            return $user->canActOnOrganization($organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, $act, $organization->name);
        }

        $company = $row->company;

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

        $franchise = $row->franchise;

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

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
