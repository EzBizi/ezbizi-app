<?php

namespace App\Services\A1Leads;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\ViewScope;

/**
 * Is the company A1 Leads' data belongs to (A-1 Chimney) in this person's view?
 *
 * The one answer the A1Leads GBP tab, its replies and its dashboard box all
 * obey - Jose, 2026-09-24: "this belongs to a-1chimney". Yes for the
 * organization view (it contains A-1 Chimney), A-1 Chimney itself, or one of
 * its franchises; no for any other company.
 */
final class A1LeadsOwner
{
    public static function inView(User $user, string $companyId): bool
    {
        $view = ViewScope::forUser($user);
        if ($view->isOrganization()) {
            return $user->reachableCompanies()->contains(fn ($company) => $company->getKey() === $companyId);
        }
        if ($view->type === ViewScope::FRANCHISE) {
            return Franchise::find($view->id)?->company_id === $companyId;
        }

        return in_array($companyId, $view->companyIds(), true);
    }
}
