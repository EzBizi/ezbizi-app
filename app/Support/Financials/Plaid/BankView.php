<?php

namespace App\Support\Financials\Plaid;

use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which banks (Plaid connections) a person sees in their current view. The
 * Bank tab and the dashboard glance both ask this, so they cannot disagree.
 *
 * Stricter than social pages: a bank is money, so it is seen only from the
 * place it was connected or above. The organization view sees every bank; a
 * company view sees its own and its branches'; a branch view sees its own.
 * An organization's bank does not appear inside a company or a branch.
 */
final class BankView
{
    public function __construct(private readonly SocialConnectionAccess $access) {}

    /** @return Collection<int, SocialConnection> */
    public function banks(User $user): Collection
    {
        $view = ViewScope::forUser($user);

        return SocialConnection::query()->where('provider', SocialProvider::Plaid->value)->orderBy('display_name')->get()
            ->filter(fn (SocialConnection $c) => self::sees($view, $c) && $this->access->reaches($user, $c))
            ->values();
    }

    public static function sees(ViewScope $view, SocialConnection $c): bool
    {
        return match ($c->scope_type) {
            SocialConnection::SCOPE_ORGANIZATION => $view->isOrganization(),
            SocialConnection::SCOPE_COMPANY => $view->isOrganization() || ($view->type === ViewScope::COMPANY && in_array($c->scope_id, $view->companyIds(), true)),
            SocialConnection::SCOPE_FRANCHISE => in_array($c->scope_id, $view->franchiseIds(), true),
            default => false,
        };
    }
}
