<?php

namespace App\Support\Jobs;

use App\Models\Franchise;
use App\Models\Job;
use App\Support\Settings\SettingsResolver;

/**
 * Which job statuses mean the money is earned (spec §8.2).
 *
 * One place, because this answer is load-bearing twice: it decides WHICH jobs
 * count as revenue and — through the transition that stamps `earned_at` — WHEN
 * they count. It used to live in `user.preferences`, which made it a personal
 * opinion about a company fact; two people could disagree about whether a job
 * was revenue, and `earned_at` has room for only one answer.
 *
 * Promoting it to a `jobs.earned_statuses` setting also gains an audit trail
 * the per-user preference never had — a write here is logged as
 * `settings.updated` (SettingsController::update()), while the dashboard
 * preference write it replaces was never audited at all.
 */
final class EarnedStatuses
{
    /** @return list<string> */
    public static function for(Franchise $scope): array
    {
        $chosen = app(SettingsResolver::class)->get('jobs.earned_statuses', $scope);

        if (! is_array($chosen)) {
            return [];
        }

        // Intersect with the vocabulary the organization actually publishes. A
        // renamed status must stop meaning earned rather than silently keeping
        // a value nothing can reach.
        $published = Job::allowedStatuses($scope);

        return array_values(array_intersect(
            array_filter($chosen, 'is_string'),
            $published,
        ));
    }

    public static function isEarned(string $status, Franchise $scope): bool
    {
        return in_array($status, self::for($scope), strict: true);
    }
}
