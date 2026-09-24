<?php

namespace App\Policies;

use App\Models\AssistantEntry;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\OwnedScope;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and write the AI Assistance Library.
 *
 * The Library tab is the AUTHORING surface: reading it needs
 * `ai_assistance.library.view`, writing needs `ai_assistance.manage`. Asking
 * the assistant is a different act, gated on the AI tab by
 * AssistantController, and never reads an answer through this policy - it
 * goes through AssistantBrain, so the app and WhatsApp share one rule.
 *
 * Past the permission it is TrainingGuidePolicy's ownership, verb for verb:
 * an organization answer is everybody's to read and an organization act to
 * write; a company's answer is read by whoever sees that company; a
 * franchise's only by whoever reaches that franchise.
 */
class AssistantEntryPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('ai_assistance.library.view')
            ? Response::allow()
            : Refusal::permission('see the assistant\'s answers');
    }

    public function view(User $user, AssistantEntry $entry): Response
    {
        if (! $user->hasPermission('ai_assistance.library.view')) {
            return Refusal::permission('see the assistant\'s answers');
        }

        return $this->within($user, $entry, 'see this answer');
    }

    public function create(User $user, ?Company $company, ?Franchise $franchise = null): Response
    {
        if (! $user->hasPermission('ai_assistance.manage')) {
            return Refusal::permission('add an answer');
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
                : Refusal::noReach($user, Refusal::AT_FRANCHISE, 'add an answer', $franchise->name);
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, 'add an answer', $company->name);
    }

    public function createOnOrganization(User $user): Response
    {
        if (! $user->hasPermission('ai_assistance.manage')) {
            return Refusal::permission('add an answer');
        }

        $organization = $user->organization;

        return $user->canActOnOrganization($organization)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_ORGANIZATION, 'add an answer', $organization->name);
    }

    public function update(User $user, AssistantEntry $entry): Response
    {
        if (! $user->hasPermission('ai_assistance.manage')) {
            return Refusal::permission('change an answer');
        }

        return $this->within($user, $entry, 'change this answer', write: true);
    }

    public function delete(User $user, AssistantEntry $entry): Response
    {
        if (! $user->hasPermission('ai_assistance.manage')) {
            return Refusal::permission('delete an answer');
        }

        return $this->within($user, $entry, 'delete this answer', write: true);
    }

    private function within(User $user, AssistantEntry $entry, string $act, bool $write = false): Response
    {
        if ($entry->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($write) {
            return OwnedScope::mayWrite(
                $user,
                $entry->organization_id,
                $entry->franchise_id !== null ? 'franchise' : ($entry->company_id !== null ? 'company' : 'organization'),
                $entry->franchise_id ?? $entry->company_id,
            ) ? Response::allow() : Response::deny('You cannot change this answer from your current view or assignments.');
        }

        if ($entry->company_id === null) {
            return Response::allow();
        }

        $company = Company::find($entry->company_id);

        if ($company === null || ! $this->canSeeCompany($user, $company)) {
            return Refusal::noReach($user, Refusal::AT_COMPANY, $act, $company?->name ?? 'that company');
        }

        if ($entry->franchise_id === null) {
            return Response::allow();
        }

        $franchise = Franchise::find($entry->franchise_id);

        if ($franchise === null) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }

    /** Franchise assignments see their company's answers, as with guides. */
    private function canSeeCompany(User $user, Company $company): bool
    {
        return $user->canActOnCompany($company)
            || $user->reachableFranchises()->contains(fn (Franchise $f) => $f->company_id === $company->getKey());
    }
}
