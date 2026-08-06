<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\Widget;

/**
 * How much work is in each state.
 *
 * The statuses come from the organization's own settings (§2.1 category 5), not
 * from a list in here — a business that calls it "Booked / On site / Invoiced"
 * would otherwise see a dashboard using somebody else's words.
 */
class JobsByStatus implements Widget
{
    use ScopesToReach;

    public function key(): string { return 'jobs.by_status'; }

    public function label(): string { return 'Jobs by status'; }

    public function permission(): string { return 'jobs.view'; }

    public function options(User $user): array
    {
        return [
            'include_finished' => [
                'label'   => 'Include finished work',
                'type'    => 'bool',
                'default' => false,
                'help'    => 'Off by default, so the board shows what still needs doing '
                    .'rather than everything that ever happened.',
            ],
        ];
    }

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
