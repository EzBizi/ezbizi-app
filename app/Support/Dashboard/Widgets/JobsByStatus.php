<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Scoping\ViewScope;

/**
 * How much work is in each state.
 *
 * The statuses come from the organization's own settings (§2.1 category 5), not
 * from a list in here — a business that calls it "Booked / On site / Invoiced"
 * would otherwise see a dashboard using somebody else's words.
 */
class JobsByStatus
{
    public function resolve(User $user, array $options): array
    {
        // The VIEW, not merely the reach: ViewScope has already intersected the
        // chosen scope with what §4 allows. `whereIn` on an empty list matches
        // nothing, which is the right answer for somebody with no assignments.
        $ids = ViewScope::forUser($user)->franchiseIds();

        // Statuses are tenant configuration and resolve through whichever
        // franchise the view covers.
        $franchise = $user->reachableFranchises()->whereIn('id', $ids)->first()
            ?? $user->reachableFranchises()->first();
        $statuses = $franchise instanceof Franchise ? Job::allowedStatuses($franchise) : [];

        $counts = Job::query()
            ->whereIn('franchise_id', $ids)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every configured status appears, including the ones with nothing in
        // them. A board that hides empty columns reorders itself as work moves,
        // which is exactly when somebody is looking at it. A status counted in
        // reach but missing from that vocabulary (another branch's own word, or
        // one since removed) is appended, so the slices add up to the total.
        $rows = [];
        foreach ($statuses as $status) {
            $rows[$status] = [
                'status' => $status,
                'label' => Job::labelFor($status, $franchise),
                'total' => (int) ($counts[$status] ?? 0),
            ];
        }
        foreach ($counts as $status => $total) {
            $status = (string) $status;
            if (! isset($rows[$status])) {
                $rows[$status] = [
                    'status' => $status,
                    'label' => Job::humanizeStatus($status),
                    'total' => (int) $total,
                ];
            }
        }

        return [
            'rows' => array_values($rows),

            'total' => (int) $counts->sum(),
        ];
    }
}
