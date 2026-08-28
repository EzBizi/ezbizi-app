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

        $earned = array_intersect(
            array_filter($chosen, 'is_string'),
            $published,
        );

        // Dedupe defensively, HERE, and not because the type layer already
        // did it. This setting is TYPE_JSON, not TYPE_LIST — a TYPE_LIST
        // default cannot be empty (castList() throws on `[]`) and this
        // setting's default is `[]` (see the docblock on jobs.earned_statuses
        // in SettingRegistry.php). castJson() only checks is_array(); unlike
        // castList() it does not enforce array_is_list() or uniqueness. So a
        // stored value can be a string-keyed map —
        // {"a":"completed","b":"completed"} — and still pass cast()
        // validation. array_intersect() above matches by VALUE, not key, so
        // it carries every duplicate straight through. This array_unique() is
        // the only place left in the pipeline that can still guarantee the
        // `list<string>` contract this method documents — one entry per
        // status. Do not delete it as "redundant" with the cast; the cast
        // stopped guaranteeing that the moment this became TYPE_JSON.
        return array_values(array_unique($earned));
    }

    public static function isEarned(string $status, Franchise $scope): bool
    {
        return in_array($status, self::for($scope), strict: true);
    }
}
