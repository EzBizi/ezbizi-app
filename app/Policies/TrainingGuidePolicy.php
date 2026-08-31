<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingGuide;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and write training guides.
 *
 * `/training/guides` is the AUTHORING surface — the Settings tab, not the
 * Learn tab a learner reads from. The spec's tab table maps Settings to
 * `manage`, Learn to `view`, Dashboard to `monitor`; gating reads here on
 * `training.view` would hand every guide, drafts included, to anyone who
 * merely holds Learn access. So every verb below — read and write alike —
 * checks `training.manage`, and `training.view` gates the learner-facing
 * `GET /training/learn` that Task 6 adds instead. One permission, one
 * controller.
 *
 * Mirrors InventoryItemPolicy, verb for verb, past the permission check:
 * General (`franchise_id` null) is visible to anyone who can see that
 * company, including a franchise-only user assigned under it. Organization
 * guides (`company_id` null) are visible throughout the tenant; writing one
 * is an organization act.
 */
class TrainingGuidePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('training.manage')
            ? Response::allow()
            : Refusal::permission('see training');
    }

    public function view(User $user, TrainingGuide $guide): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('see training');
        }

        return $this->within($user, $guide, 'see this guide');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('add a guide');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a guide', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a guide', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('add a guide');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a guide',
                $organization->name,
            );
    }

    public function update(User $user, TrainingGuide $guide): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('change a guide');
        }

        return $this->within($user, $guide, 'change this guide', write: true);
    }

    public function delete(User $user, TrainingGuide $guide): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('delete a guide');
        }

        return $this->within($user, $guide, 'delete this guide', write: true);
    }

    private function within(User $user, TrainingGuide $guide, string $act, bool $write = false): Response
    {
        if ($guide->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($guide->company_id === null) {
            if (! $write) {
                return Response::allow();
            }

            $organization = $user->organization;

            return $user->canActOnOrganization($organization)
                ? Response::allow()
                : Refusal::noReach($user, Refusal::AT_ORGANIZATION, $act, $organization->name);
        }

        // Looked up rather than read off a `company()` relation: TrainingGuide
        // declares none (see the model), and adding one is not this task's
        // file to change.
        $company = Company::find($guide->company_id);

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($guide->franchise_id === null) {
            return Response::allow();
        }

        $franchise = Franchise::find($guide->franchise_id);

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /**
     * Soft company reach for reading the catalogue — franchise assignments see
     * their company's General even though they cannot `canActOnCompany`.
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
