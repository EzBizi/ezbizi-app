<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a vehicle (spec §4, §9).
 *
 * Reach is company + optional franchise: the company pool (`franchise_id` null)
 * is visible to anyone who can see that company, including franchise-only
 * people assigned under it. Create still requires `canActOnCompany` — writing
 * the register is a company act.
 */
class VehiclePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('fleet_management.view')
            ? Response::allow()
            : Refusal::permission('see the fleet');
    }

    public function view(User $user, Vehicle $vehicle): Response
    {
        if (! $user->hasPermission('fleet_management.view')) {
            return Refusal::permission('see the fleet');
        }

        return $this->within($user, $vehicle, 'see this vehicle');
    }

    public function create(User $user, Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('fleet_management.create')) {
            return Refusal::permission('add a vehicle');
        }

        if (! $user->canActOnCompany($company)) {
            return Refusal::noReach($user, Refusal::AT_COMPANY, 'add a vehicle', $company->name);
        }

        if ($franchise === null) {
            return Response::allow();
        }

        if ($franchise->company_id !== $company->getKey()) {
            return Response::deny('That place is not part of this company.');
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a vehicle', $franchise->name);
    }

    public function update(User $user, Vehicle $vehicle): Response
    {
        if (! $user->hasPermission('fleet_management.edit')) {
            return Refusal::permission('change a vehicle');
        }

        return $this->within($user, $vehicle, 'change this vehicle');
    }

    public function delete(User $user, Vehicle $vehicle): Response
    {
        if (! $user->hasPermission('fleet_management.delete')) {
            return Refusal::permission('delete a vehicle');
        }

        return $this->within($user, $vehicle, 'delete this vehicle');
    }

    public function assign(User $user, Vehicle $vehicle): Response
    {
        if (! $user->hasPermission('fleet_management.assign')) {
            return Response::deny(
                'You can view this vehicle, but you cannot hand over the keys.',
            );
        }

        return $this->within($user, $vehicle, 'assign this vehicle');
    }

    public function financials(User $user, Vehicle $vehicle): Response
    {
        if (! $user->hasPermission('fleet_management.financials')) {
            return Response::deny('Amounts on the fleet are hidden for your role.');
        }

        return $this->within($user, $vehicle, 'see amounts on this vehicle');
    }

    private function within(User $user, Vehicle $vehicle, string $act): Response
    {
        if ($vehicle->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        $company = $vehicle->company;

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($vehicle->franchise_id === null) {
            return Response::allow();
        }

        $franchise = $vehicle->franchise;

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading the register — franchise assignments see
     * their company’s pool even though they cannot `canActOnCompany`.
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
