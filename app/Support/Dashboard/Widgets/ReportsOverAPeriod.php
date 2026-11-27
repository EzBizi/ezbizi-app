<?php

namespace App\Support\Dashboard\Widgets;

/**
 * The rolling window a widget reports on.
 *
 * ── Why this is its own trait ────────────────────────────────────
 *
 * It began inside the old ScopesToReach, next to the franchise filter, because at the
 * time every widget wanted both. The HR widgets want the period and must NOT
 * have the franchise filter: people are assigned at organization, company or
 * franchise level (§4), so a `where franchise_id in (…)` would silently drop
 * everybody placed above a franchise — including the founding administrator,
 * who is assigned at organization level and is the only person most new
 * organizations have.
 *
 * So the two facts are separated rather than one trait being used for half of
 * what it offers. A widget that helps itself to `reachable()` because it came
 * along with `since()` is the kind of accident that produces a dashboard which
 * looks right and counts the wrong people.
 */
trait ReportsOverAPeriod
{
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
}
