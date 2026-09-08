<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a vendor (spec §8.3).
 *
 * Same tree and place-write as catalogue items: organization, company, or
 * franchise. General is visible to anyone who can see that company.
 */
class InventoryVendorPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventoryVendor $vendor): Response
    {
        if (! $user->hasPermission('inventory.view')) {
            return Refusal::permission('see inventory');
        }

        return $this->within($user, $vendor, 'see this vendor');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a vendor');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a vendor', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a vendor', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a vendor');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a vendor',
                $organization->name,
            );
    }

    public function update(User $user, InventoryVendor $vendor): Response
    {
        if (! $user->hasPermission('inventory.edit')) {
            return Refusal::permission('change a vendor');
        }

        return $this->within($user, $vendor, 'change this vendor', write: true);
    }

    public function delete(User $user, InventoryVendor $vendor): Response
    {
        if (! $user->hasPermission('inventory.delete')) {
            return Refusal::permission('delete a vendor');
        }

        return $this->within($user, $vendor, 'delete this vendor', write: true);
    }

    private function within(User $user, InventoryVendor $vendor, string $act, bool $write = false): Response
    {
        if ($vendor->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $vendor->organization_id,
                $vendor->franchise_id !== null ? 'franchise' : ($vendor->company_id !== null ? 'company' : 'organization'),
                $vendor->franchise_id ?? $vendor->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this record from your current view or assignments.');
        }
        if ($vendor->company_id === null) {
            return Response::allow();
        }

        $company = $vendor->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($vendor->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $vendor->franchise;

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading vendors — franchise assignments see
     * their company’s General even though they cannot `canActOnCompany`.
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
