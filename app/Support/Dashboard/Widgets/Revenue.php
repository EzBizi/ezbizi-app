<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\Widget;

/**
 * What the work was worth.
 *
 * ── Currencies are never added together ──────────────────────────
 *
 * Amounts are minor units with a currency attached, resolved from the
 * franchise. Summing across them produces a number that is wrong in a way
 * nobody notices until a client reconciles it against their books — and by then
 * every figure the dashboard ever showed them is suspect.
 *
 * So this returns one row per currency and lets the client show two lines. An
 * organization operating in one currency sees exactly what it expects; one
 * operating in two is told the truth instead of a total.
 *
 * ── Which statuses count is the organization's decision ──────────
 *
 * "Completed" is not a word this platform owns — §2.1 category 5 puts the status
 * vocabulary in tenant settings. Guessing here would produce a revenue figure
 * that silently disagrees with the business's own definition of earned.
 */
class Revenue implements Widget
{
    use ScopesToReach;

    public function key(): string { return 'jobs.revenue'; }

    public function label(): string { return 'Revenue'; }

    public function permission(): string { return 'jobs.view'; }

    public function options(User $user): array
    {
        return [
            'days' => $this->periodOption(30),

            'statuses' => [
                'label'   => 'Count jobs in these statuses',
                'type'    => 'multi',
                'options' => $this->statusesFor($user),
                'default' => [],
                'help'    => 'Whichever of your statuses means the money is earned. '
                    .'Left empty, nothing is counted rather than everything — a revenue '
                    .'figure that quietly includes cancelled work is worse than a blank one.',
            ],
        ];
    }

    public function resolve(User $user, array $options): array
    {
        $statuses = array_values(array_intersect(
            (array) ($options['statuses'] ?? []),
            $this->statusesFor($user),
        ));

        if ($statuses === []) {
            return [
                'rows'  => [],
                'needs_configuring' => true,
                'message' => 'Choose which of your job statuses count as earned.',
            ];
        }

        $rows = $this->reachable(Job::query(), $user)
            ->whereIn('status', $statuses)
            ->where('created_at', '>=', $this->since($options))
            ->selectRaw('currency, SUM(amount_minor) as total, COUNT(*) as jobs')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => [
                'currency'     => $row->currency,
                // Minor units all the way to the client, which formats them.
                // Dividing by 100 here assumes two decimal places, which is
                // wrong for JPY and wrong for the day this sells abroad.
                'amount_minor' => (int) $row->total,
                'jobs'         => (int) $row->jobs,
            ])->all(),
            'needs_configuring' => false,
        ];
    }
}
