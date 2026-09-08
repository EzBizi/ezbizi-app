<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a sales order (spec §8.5).
 *
 * Creating a draft is inventory.create at a place the caller can write.
 * Opening, cancelling, paying, and fulfilling travel with inventory.edit.
 */
class InventorySalePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventorySale $sale): Response
    {
        if (! $user->hasPermission('inventory.view')) {
            return Refusal::permission('see inventory');
        }

        return $this->within($user, $sale, 'see this sale');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a sale');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a sale', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a sale', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a sale');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a sale',
                $organization->name,
            );
    }

    public function update(User $user, InventorySale $sale): Response
    {
        if (! $user->hasPermission('inventory.edit')) {
            return Refusal::permission('change a sale');
        }

        return $this->within($user, $sale, 'change this sale', write: true);
    }

    public function fulfill(User $user, InventorySale $sale, InventoryLocation $location): Response
    {
        $saleWrite = $this->update($user, $sale);

        if ($saleWrite->denied()) {
            return $saleWrite;
        }

        return app(InventoryLocationPolicy::class)->update($user, $location);
    }

    private function within(User $user, InventorySale $sale, string $act, bool $write = false): Response
    {
        if ($sale->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $sale->organization_id,
                $sale->franchise_id !== null ? 'franchise' : ($sale->company_id !== null ? 'company' : 'organization'),
                $sale->franchise_id ?? $sale->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this record from your current view or assignments.');
        }
        if ($sale->company_id === null) {
            return Response::allow();
        }

        $company = $sale->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($sale->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $sale->franchise;

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
