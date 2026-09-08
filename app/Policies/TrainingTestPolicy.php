<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and write training tests.
 *
 * `/training/tests` is the AUTHORING surface — the Settings tab, not the
 * Learn tab a learner reads from. Every verb below — read and write alike —
 * checks `training.manage`; `training.view` gates the learner-facing
 * `GET /training/learn` that Task 6 adds instead. One permission, one
 * controller. See TrainingGuidePolicy's docblock — this is byte-identical
 * reasoning, one row over.
 *
 * Mirrors TrainingGuidePolicy and InventoryItemPolicy, verb for verb: General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including a franchise-only user assigned under it. Organization tests
 * (`company_id` null) are visible throughout the tenant; writing one is an
 * organization act.
 */
class TrainingTestPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('training.manage')
            ? Response::allow()
            : Refusal::permission('see training');
    }

    public function view(User $user, TrainingTest $test): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('see training');
        }

        return $this->within($user, $test, 'see this test');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('add a test');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add a test', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add a test', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('add a test');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_ORGANIZATION,
                'add a test',
                $organization->name,
            );
    }

    public function update(User $user, TrainingTest $test): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('change a test');
        }

        return $this->within($user, $test, 'change this test', write: true);
    }

    public function delete(User $user, TrainingTest $test): Response
    {
        if (! $user->hasPermission('training.manage')) {
            return Refusal::permission('delete a test');
        }

        return $this->within($user, $test, 'delete this test', write: true);
    }

    private function within(User $user, TrainingTest $test, string $act, bool $write = false): Response
    {
        if ($test->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $test->organization_id,
                $test->franchise_id !== null ? 'franchise' : ($test->company_id !== null ? 'company' : 'organization'),
                $test->franchise_id ?? $test->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this record from your current view or assignments.');
        }
        if ($test->company_id === null) {
            return Response::allow();
        }

        // Looked up rather than read off a `company()` relation: TrainingTest
        // declares none (see the model), and adding one is not this task's
        // file to change.
        $company = Company::find($test->company_id);

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                $act,
                $company?->name ?? 'that company',
            );
        }

        if ($test->franchise_id === null) {
            return Response::allow();
        }

        $franchise = Franchise::find($test->franchise_id);

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
