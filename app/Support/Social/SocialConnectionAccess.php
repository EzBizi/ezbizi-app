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
    /** @var array<string, mixed>|null lookups shared across one presentableInView() pass */
    private ?array $memo = null;

    private function once(string $key, \Closure $load): mixed
    {
        if ($this->memo === null) {
            return $load();
        }

        return array_key_exists($key, $this->memo) ? $this->memo[$key] : ($this->memo[$key] = $load());
    }

    public function inView(User $user, SocialConnection $connection): bool
    {
        $view = $this->once('view:'.$user->getKey(), fn () => ViewScope::forUser($user));

        // Answered from reach, not from the scope: ViewScope::forUser falls back
        // to an organization view for anyone who never touched the switcher, so
        // a branch-only user arrives here claiming the whole tenant. Same trap
        // as InventoryVisibility::companyIdsFor. A company page is reached
        // through one of its branches too, as the franchise arm below shows it.
        if ($view->isOrganization()) {
            return match ($connection->scope_type) {
                SocialConnection::SCOPE_ORGANIZATION => true,
                SocialConnection::SCOPE_COMPANY => $this->reaches($user, $connection)
                    || $this->once('reach:'.$user->getKey(), fn () => $user->reachableFranchises())->contains('company_id', $connection->scope_id),
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

        $franchise = $this->once('f:'.$view->id, fn () => Franchise::find($view->id));

        return match ($connection->scope_type) {
            SocialConnection::SCOPE_ORGANIZATION => true,
            SocialConnection::SCOPE_COMPANY => $franchise !== null
                && $connection->scope_id === $franchise->company_id,
            SocialConnection::SCOPE_FRANCHISE => $connection->scope_id === $view->id,
            default => false,
        };
    }

    /**
     * One provider's connections this person may see, as the `connections`
     * meta Business Profile and Reviews send. The id is what lets the tab that
     * owns a connection remove it; without it the only disconnect was in
     * Marketing, a module these providers are kept out of.
     *
     * @return list<array{id: mixed, provider: string, display_name: string, status: string}>
     */
    public function presentableInView(?User $user, SocialProvider $provider): array
    {
        // One view, one reach list, one lookup per company/branch for the
        // whole list — not a fresh set of queries per row.
        $this->memo = [];

        try {
            $rows = SocialConnection::query()
                ->where('provider', $provider->value)
                ->get()
                ->filter(fn (SocialConnection $c) => $user !== null && $this->inView($user, $c));
        } finally {
            $this->memo = null;
        }

        return $rows
            ->map(fn (SocialConnection $c) => [
                'id'           => $c->getKey(),
                'provider'     => $c->provider,
                'display_name' => $c->display_name,
                'status'       => $c->status,
            ])
            ->values()
            ->all();
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

            SocialConnection::SCOPE_COMPANY => ($company = $this->once('c:'.$scopeId, fn () => Company::find($scopeId))) !== null
                && $user->canActOnCompany($company),

            SocialConnection::SCOPE_FRANCHISE => ($franchise = $this->once('f:'.$scopeId, fn () => Franchise::find($scopeId))) !== null
                && $user->canActOnFranchise($franchise),

            default => false,
        };
    }
}
