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
        // Intersect with the vocabulary the organization actually publishes. A
        // renamed status must stop meaning earned rather than silently keeping
        // a value nothing can reach -- and it is the ONLY check that a value is
        // a real status: the setting's cast deliberately does not compare them
        // (Settings, 95258969), because this line does.
        //
        // Nothing else is defended here any more. The setting became
        // TYPE_SUBSET in 95258969 and the resolver casts on READ, so every value
        // reaching this method is already a de-duplicated list of non-empty
        // text, including a string-keyed map stored under the old TYPE_JSON.
        // This method used to carry an is_array() guard, an is_string filter and
        // an array_unique(), with a comment saying not to remove the last one
        // "because the cast stopped guaranteeing that the moment this became
        // TYPE_JSON". It is not TYPE_JSON now, so the guarantee is back where it
        // belongs and the three layers were deleted rather than re-justified.
        // JobEarnedStatusesTest still pins the outcome whichever layer supplies it.
        return array_values(array_intersect(
            app(SettingsResolver::class)->get('jobs.earned_statuses', $scope),
            Job::allowedStatuses($scope),
        ));
    }

    public static function isEarned(string $status, Franchise $scope): bool
    {
        return in_array($status, self::for($scope), strict: true);
    }
}
