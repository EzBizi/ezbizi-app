<?php

namespace App\Support\Scoping;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;

/** Write ownership is hard assignment authority intersected with the displayed view. */
final class OwnedScope
{
    public static function mayWrite(User $user, string $organizationId, string $scopeType, ?string $scopeId): bool
    {
        if (! $user->is_active || $user->organization_id !== $organizationId) {
            return false;
        }

        $view = ViewScope::forUser($user);
        if ($scopeType === Assignment::SCOPE_ORGANIZATION) {
            return ($scopeId === null || $scopeId === $organizationId)
                && $view->isOrganization()
                && $user->canActOnOrganization($user->organization);
        }
        if ($scopeType === Assignment::SCOPE_COMPANY) {
            $company = Company::query()->where('organization_id', $organizationId)->find($scopeId);

            return $company !== null && $user->canActOnCompany($company)
                && $view->type !== ViewScope::FRANCHISE && $view->coversCompany($company->id);
        }
        if ($scopeType === Assignment::SCOPE_FRANCHISE) {
            $franchise = Franchise::query()->where('organization_id', $organizationId)->find($scopeId);

            return $franchise !== null && $user->canActOnFranchise($franchise)
                && $view->coversFranchise($franchise->id);
        }

        return false;
    }
}
