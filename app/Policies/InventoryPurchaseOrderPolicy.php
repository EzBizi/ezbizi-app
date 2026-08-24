<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a purchase order (spec §8.3).
 *
 * Place-write matches items: creating a draft is inventory.create at a place
 * the caller can write. Opening, cancelling, and receiving travel with
 * inventory.edit.
 */
class InventoryPurchaseOrderPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventoryPurchaseOrder $order): Response
    {
        if (! $user->hasPermission('inventory.view')) {
            return Refusal::permission('see inventory');
        }

        return $this->within($user, $order, 'see this order');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a purchase order');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a purchase order', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a purchase order', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add a purchase order');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a purchase order',
                $organization->name,
            );
    }

    public function update(User $user, InventoryPurchaseOrder $order): Response
    {
        if (! $user->hasPermission('inventory.edit')) {
            return Refusal::permission('change an order');
        }

        return $this->within($user, $order, 'change this order', write: true);
    }

    private function within(User $user, InventoryPurchaseOrder $order, string $act, bool $write = false): Response
    {
        if ($order->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($order->company_id === null) {
            if (! $write) {
                return Response::allow();
            }

            $organization = $user->organization;

            return $user->canActOnOrganization($organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, $act, $organization->name);
        }

        $company = $order->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($order->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $order->franchise;

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
