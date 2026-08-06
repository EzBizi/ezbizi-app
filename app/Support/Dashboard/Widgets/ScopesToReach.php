<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two things every widget gets wrong if left to itself.
 *
 * Shared rather than repeated per widget, because both failures are silent. A
 * widget that forgets reach shows a franchise manager the whole organization's
 * numbers and looks like it is working. A widget that reads a status list from
 * a constant shows the wrong words to any business that renamed them.
 */
trait ScopesToReach
{
    /**
     * Narrow a query to the franchises this person may act on (§4).
     *
     * `whereIn` on an empty list matches nothing, which is the correct answer
     * for somebody with no assignments — and the reason this is not written as
     * a conditional that skips the clause when the list is empty.
     */
    protected function reachable(Builder $query, User $user): Builder
    {
        return $query->whereIn('franchise_id', $user->reachableFranchises()->pluck('id'));
    }

    /** The organization's own job vocabulary (§2.1 category 5). */
    protected function statusesFor(User $user): array
    {
        $franchise = $user->reachableFranchises()->first();

        return $franchise instanceof Franchise
            ? Job::allowedStatuses($franchise)
            : [];
    }

    /**
     * The window a widget reports on.
     *
     * Days rather than "this month", because a month boundary makes the first of
     * the month look like a collapse in business and is the single most common
     * complaint about dashboards.
     */
    protected function since(array $options, int $default = 30): \Illuminate\Support\Carbon
    {
        $days = (int) ($options['days'] ?? $default);

        // Clamped: an unbounded window invites a full-table scan from a URL, and
        // nobody has a use for a ten-year rolling count on a dashboard.
        return now()->subDays(max(1, min($days, 365)));
    }

    protected function periodOption(int $default = 30): array
    {
        return [
            'label'   => 'Period',
            'type'    => 'enum',
            'options' => [7, 30, 90, 365],
            'default' => $default,
            'help'    => 'A rolling window, not a calendar month — so the first of the '
                .'month does not look like business fell off a cliff.',
        ];
    }

    protected function resolver(): SettingsResolver
    {
        return app(SettingsResolver::class);
    }
}
