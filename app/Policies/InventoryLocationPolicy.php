<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a stock location (spec §4, §6).
 *
 * Reach is organization, then company + optional franchise. General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including franchise-only people assigned under it. Organization locations
 * (`company_id` null) are visible throughout the tenant; writing them is an
 * organization act.
 */
class InventoryLocationPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventoryLocation $location): Response
    {
        if (! $user->hasPermission('inventory.view')) {
            return Refusal::permission('see inventory');
        }

        return $this->within($user, $location, 'see this location');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a location');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a location', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a location', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a location');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a location',
                $organization->name,
            );
    }

    public function update(User $user, InventoryLocation $location): Response
    {
        if (! $user->hasPermission('inventory.edit')) {
            return Refusal::permission('change a location');
        }

        return $this->within($user, $location, 'change this location', write: true);
    }

    public function delete(User $user, InventoryLocation $location): Response
    {
        if (! $user->hasPermission('inventory.delete')) {
            return Refusal::permission('delete a location');
        }

        return $this->within($user, $location, 'delete this location', write: true);
    }

    private function within(User $user, InventoryLocation $location, string $act, bool $write = false): Response
    {
        if ($location->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($location->company_id === null) {
            if (! $write) {
                return Response::allow();
            }

            $organization = $user->organization;

            return $user->canActOnOrganization($organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, $act, $organization->name);
        }

        $company = $location->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($location->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $location->franchise;

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading locations — franchise assignments see
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
