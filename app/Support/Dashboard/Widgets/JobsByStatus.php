<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Job;
use App\Models\User;

/**
 * How much work is in each state.
 *
 * The statuses come from the organization's own settings (§2.1 category 5), not
 * from a list in here — a business that calls it "Booked / On site / Invoiced"
 * would otherwise see a dashboard using somebody else's words.
 */
class JobsByStatus
{
    use ScopesToReach;

    public function resolve(User $user, array $options): array
    {
        $statuses = $this->statusesFor($user);

        $counts = $this->reachable(Job::query(), $user)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            // Every configured status appears, including the ones with nothing
            // in them. A board that hides empty columns reorders itself as work
            // moves, which is exactly when somebody is looking at it.
            'rows' => array_map(fn (string $status) => [
                'status' => $status,
                'total'  => (int) ($counts[$status] ?? 0),
            ], $statuses),

            'total' => (int) $counts->sum(),
        ];
    }
}
