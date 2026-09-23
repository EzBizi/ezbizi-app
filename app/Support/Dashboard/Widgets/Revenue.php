<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\Widget;
use App\Support\Jobs\EarnedStatuses;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Carbon;

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
 *
 * And it is the ORGANIZATION's decision, not each reader's: the list lives in
 * the `jobs.earned_statuses` setting (§8.2). It was a per-user widget option
 * until 2026-09-23, which made a company fact into a personal opinion — two
 * people could disagree about whether a job was revenue, and `earned_at` has
 * room for only one answer.
 *
 * ── A closed month stays closed ──────────────────────────────────
 *
 * §8.1. This reports against `earned_at`, not `created_at`, and applies no
 * status test at all. Both of those were read-time answers to a question about
 * the past: a job booked in June and finished in September counted as June
 * revenue, and re-reading a past period returned a different number every time
 * somebody moved a job along.
 */
class Revenue implements Widget
{
    use ScopesToReach;

    public function key(): string { return 'jobs.revenue'; }

    public function label(): string { return 'Revenue'; }

    public function permission(): string { return 'jobs.view'; }

    /**
     * No `statuses` control any more — see the class docblock.
     *
     * It was a multi-select here until the answer became an organization
     * setting. A control that cannot change the answer is worse than no
     * control: it is drawn, it is tickable, it saves, and nothing moves.
     */
    public function options(User $user): array
    {
        return [
            'days' => $this->periodOption(30),
        ];
    }

    public function resolve(User $user, array $options): array
    {
        // Not $options['statuses']. The list that MEANS earned is the
        // organization's (§8.2), and it has to be, because `earned_at` is
        // stamped from that same list — a widget reading a second opinion
        // would disagree with its own history the moment two people chose
        // differently. Any 'statuses' still arriving in $options is a caller
        // that has not been updated; it is ignored rather than merged, because
        // merging is how two sources of truth survive.
        $statuses = $this->earnedStatuses($user);

        if ($statuses === []) {
            return [
                'rows'  => [],
                'needs_configuring' => true,
                'message' => 'Choose which of your job statuses count as earned.',
            ];
        }

        // Glances pass the dashboard period start as a Carbon; the old widget
        // board still uses rolling days via since().
        $since = ($options['since'] ?? null) instanceof Carbon
            ? $options['since']
            : $this->since($options);

        $rows = $this->reachable(Job::query(), $user)
            // Bucketed on when the money was EARNED, not when the job was
            // booked, and not on the status as it happens to read today. Both
            // of those made a closed month reopen: the status test is evaluated
            // at read time, so re-running the same past period returned a
            // different answer every time somebody moved a job along.
            //
            // `earned_at` is stamped once, on the first transition into an
            // earned status (JobsController::update), and never re-stamped — so
            // it already encodes the status test AS IT WAS AT THE TIME, which
            // is the whole point. That is why there is no status clause here.
            ->whereNotNull('earned_at')
            ->where('earned_at', '>=', $since)
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

    /**
     * The organization's earned list, for this person's view.
     *
     * Public because `JobsGlance` needs the same answer for its prompt, its
     * line series and the options it echoes back — and three call sites reading
     * it three ways is how the widget and the stamping came to disagree in the
     * first place. One method, one answer.
     *
     * The franchise resolution is the same four lines as
     * `ScopesToReach::statusesFor()` and is deliberately NOT extracted into
     * that trait: `ScopesToReach` is shared with every other module's widgets
     * and belongs to the Dashboard session. A shared base is the wrong place
     * to learn a Jobs concept.
     *
     * @return list<string>
     */
    public function earnedStatuses(User $user): array
    {
        $ids = ViewScope::forUser($user)->franchiseIds();

        $franchise = $user->reachableFranchises()->whereIn('id', $ids)->first()
            ?? $user->reachableFranchises()->first();

        return $franchise instanceof Franchise
            ? EarnedStatuses::for($franchise)
            : [];
    }
}
