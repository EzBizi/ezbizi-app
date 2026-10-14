<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\FinancialBase;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use Illuminate\Auth\Access\Response;

/**
 * Everything in Financials — tables, fields, records, views — is authorized
 * against the base it sits in. A base is filed like a vehicle: on the group
 * (no company), a company, or one of its places.
 */
class FinancialBasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('financials.view')
            ? Response::allow()
            : Refusal::permission('see Financials');
    }

    public function view(User $user, FinancialBase $base): Response
    {
        if (! $user->hasPermission('financials.view')) {
            return Refusal::permission('see Financials');
        }

        return $this->visible($user, $base);
    }

    public function create(User $user, ?Company $company = null, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('financials.create')) {
            return Refusal::permission('add a base');
        }

        if ($company === null) {
            return $user->canActOnOrganization($user->organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, 'add a base', $user->organization->name);
        }

        if (! $user->canActOnCompany($company)) {
            return Refusal::noReach($user, Refusal::AT_COMPANY, 'add a base', $company->name);
        }

        return $franchise === null || $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a base', $franchise->name);
    }

    /** Changing records, fields, tables and views inside a base. */
    public function update(User $user, FinancialBase $base): Response
    {
        if (! $user->hasPermission('financials.edit')) {
            return Refusal::permission('change Financials');
        }

        return $this->writable($user, $base);
    }

    public function delete(User $user, FinancialBase $base): Response
    {
        if (! $user->hasPermission('financials.delete')) {
            return Refusal::permission('delete in Financials');
        }

        return $this->writable($user, $base);
    }

    private function visible(User $user, FinancialBase $base): Response
    {
        if ($base->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return InventoryVisibility::constrain(FinancialBase::query()->whereKey($base->getKey()), $user, ViewScope::forUser($user))->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function writable(User $user, FinancialBase $base): Response
    {
        if ($base->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return OwnedScope::mayWrite(
            $user,
            $base->organization_id,
            $base->franchise_id !== null ? 'franchise' : ($base->company_id !== null ? 'company' : 'organization'),
            $base->franchise_id ?? $base->company_id,
        ) ? Response::allow() : Response::deny('You cannot change this base from your current view or assignments.');
    }
}
