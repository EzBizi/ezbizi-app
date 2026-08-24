<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a catalogue item (spec §4, §5).
 *
 * Reach is organization, then company + optional franchise. General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including franchise-only people assigned under it. Organization items
 * (`company_id` null) are visible throughout the tenant; writing them is an
 * organization act.
 */
class InventoryItemPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inventory.view')
            ? Response::allow()
            : Refusal::permission('see inventory');
    }

    public function view(User $user, InventoryItem $item): Response
    {
        if (! $user->hasPermission('inventory.view')) {
            return Refusal::permission('see inventory');
        }

        return $this->within($user, $item, 'see this item');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add an item');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add an item', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add an item', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('inventory.create')) {
            return Refusal::permission('add an item');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add an item',
                $organization->name,
            );
    }

    public function update(User $user, InventoryItem $item): Response
    {
        if (! $user->hasPermission('inventory.edit')) {
            return Refusal::permission('change an item');
        }

        return $this->within($user, $item, 'change this item', write: true);
    }

    public function delete(User $user, InventoryItem $item): Response
    {
        if (! $user->hasPermission('inventory.delete')) {
            return Refusal::permission('delete an item');
        }

        return $this->within($user, $item, 'delete this item', write: true);
    }

    private function within(User $user, InventoryItem $item, string $act, bool $write = false): Response
    {
        if ($item->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($item->company_id === null) {
            if (! $write) {
                return Response::allow();
            }

            $organization = $user->organization;

            return $user->canActOnOrganization($organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, $act, $organization->name);
        }

        $company = $item->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($item->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $item->franchise;

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading the catalogue — franchise assignments see
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
