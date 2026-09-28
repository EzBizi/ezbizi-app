<?php

namespace App\Support\Social;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Scoping\ViewScope;

/**
 * Which connected pages this person may see in this view.
 *
 * Sibling companies never share a page. Organization view sees everything
 * below. Unlike inboxes, an organization-level page is also visible down into
 * a company and a franchise — connecting on A1 Group means every company can
 * use it. Connecting on A-1 Chimney still never appears on Premier Garage.
 */
final class SocialConnectionAccess
{
    public function inView(User $user, SocialConnection $connection): bool
    {
        $view = ViewScope::forUser($user);

        // Answered from reach, not from the scope: ViewScope::forUser falls back
        // to an organization view for anyone who never touched the switcher, so
        // a branch-only user arrives here claiming the whole tenant. Same trap
        // as InventoryVisibility::companyIdsFor. A company page is reached
        // through one of its branches too, as the franchise arm below shows it.
        if ($view->isOrganization()) {
            return match ($connection->scope_type) {
                SocialConnection::SCOPE_ORGANIZATION => true,
                SocialConnection::SCOPE_COMPANY => $this->reaches($user, $connection)
                    || $user->reachableFranchises()->contains('company_id', $connection->scope_id),
                default => $this->reaches($user, $connection),
            };
        }

        if ($view->type === ViewScope::COMPANY) {
            return match ($connection->scope_type) {
                SocialConnection::SCOPE_ORGANIZATION => true,
                SocialConnection::SCOPE_COMPANY => in_array($connection->scope_id, $view->companyIds(), true),
                SocialConnection::SCOPE_FRANCHISE => in_array($connection->scope_id, $view->franchiseIds(), true),
                default => false,
            };
        }

        $franchise = Franchise::find($view->id);

        return match ($connection->scope_type) {
            SocialConnection::SCOPE_ORGANIZATION => true,
            SocialConnection::SCOPE_COMPANY => $franchise !== null
                && $connection->scope_id === $franchise->company_id,
            SocialConnection::SCOPE_FRANCHISE => $connection->scope_id === $view->id,
            default => false,
        };
    }

    public function reaches(User $user, SocialConnection $connection): bool
    {
        return $this->reachesScope($user, $connection->scope_type, $connection->scope_id);
    }

    public function reachesScope(User $user, string $scopeType, string $scopeId): bool
    {
        return match ($scopeType) {
            SocialConnection::SCOPE_ORGANIZATION => $user->organization !== null
                && $scopeId === $user->organization_id
                && $user->canActOnOrganization($user->organization),

            SocialConnection::SCOPE_COMPANY => ($company = Company::find($scopeId)) !== null
                && $user->canActOnCompany($company),

            SocialConnection::SCOPE_FRANCHISE => ($franchise = Franchise::find($scopeId)) !== null
                && $user->canActOnFranchise($franchise),

            default => false,
        };
    }
}
