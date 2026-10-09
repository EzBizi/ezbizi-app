<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
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
    // The rolling window lives in its own trait: the HR widgets need it and must
    // not inherit reachable() with it. See ReportsOverAPeriod for why.
    use ReportsOverAPeriod;

    /**
     * Narrow a query to the franchises this person may act on (§4).
     *
     * `whereIn` on an empty list matches nothing, which is the correct answer
     * for somebody with no assignments — and the reason this is not written as
     * a conditional that skips the clause when the list is empty.
     */
    protected function reachable(Builder $query, User $user): Builder
    {
        // The VIEW, not merely the reach. ViewScope has already intersected
        // the chosen scope with what §4 allows, so this is the narrower of the
        // two and never wider than reach alone.
        return $query->whereIn('franchise_id', \App\Support\Scoping\ViewScope::forUser($user)->franchiseIds());
    }

    /** The organization's own job vocabulary (§2.1 category 5). */
    protected function statusesFor(User $user): array
    {
        // Statuses are tenant configuration and resolve through whichever
        // franchise the view covers — a company that renamed its statuses
        // should see its own words, not another company's.
        $ids = \App\Support\Scoping\ViewScope::forUser($user)->franchiseIds();

        $franchise = $user->reachableFranchises()->whereIn('id', $ids)->first()
            ?? $user->reachableFranchises()->first();

        return $franchise instanceof Franchise
            ? Job::allowedStatuses($franchise)
            : [];
    }
}
