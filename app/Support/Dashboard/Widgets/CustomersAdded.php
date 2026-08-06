<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Customer;
use App\Models\User;
use App\Support\Dashboard\Widget;

/**
 * How the customer base is growing.
 *
 * ── Scoped by OWNING franchise, and that is a real decision ──────
 *
 * Customers are shared organization-wide (§5.2) — everybody can see and edit
 * them. So "reach" does not restrict who may READ a customer, and scoping this
 * widget is not a security boundary.
 *
 * It is scoped anyway, by the owning franchise, because the question a franchise
 * manager is asking is "how is MY branch doing" and an organization-wide number
 * does not answer it. The owning tag is the one that says who is accountable
 * for the relationship and who counts it in their roll-up, which makes it the
 * right one to count by.
 */
class CustomersAdded implements Widget
{
    use ScopesToReach;

    public function key(): string { return 'customers.added'; }

    public function label(): string { return 'New customers'; }

    public function permission(): string { return 'customers.view'; }

    public function options(User $user): array
    {
        return ['days' => $this->periodOption(30)];
    }

    public function resolve(User $user, array $options): array
    {
        $since = $this->since($options);

        $reachable = $user->reachableFranchises()->pluck('id');

        $mine = Customer::query()
            ->whereIn('owning_franchise_id', $reachable)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'total' => $mine,
            'days'  => (int) $since->diffInDays(now()),

            // The organization-wide figure sits beside it rather than replacing
            // it, so somebody running one branch can see their share without
            // having to guess whether the number they are looking at is theirs.
            'organization_total' => Customer::query()
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }
}
