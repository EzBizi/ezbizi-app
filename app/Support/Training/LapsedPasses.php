<?php

namespace App\Support\Training;

use App\Models\TrainingAttempt;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "this person's training pass has lapsed" (training
 * task-11 brief).
 *
 * Task 10's nightly sweep (`ExpireTrainingPasses`) invented this definition
 * first; task 11 needs the identical fact in two more places — the
 * Dashboard's `retaking` count and people pane, and the Learn read that
 * makes a lapsed pass reappear as due for retaking. Three call sites
 * computing "lapsed" independently is exactly the failure this module has
 * spent ten tasks avoiding (see this module's own history: two idempotency
 * guards nothing could tell apart, two copies of a §4 scope check). So this
 * class holds the definition once, and `ExpireTrainingPasses::isSuperseded`
 * now calls [isSuperseded] here instead of carrying its own copy — moved
 * verbatim, not rewritten, so the sweep's own settled behaviour (and every
 * test pinning it in `TrainingExpiryTest`) is untouched.
 *
 * ── `taken_at`, never `expires_on`, decides "current" ──────────────
 *
 * Which of a person's PASSED attempts on a test is their CURRENT standing is
 * decided by when they sat it, not by when it runs out: a later change to a
 * test's own `valid_for_days` can move `expires_on` without the person
 * having done anything new, and following it instead would mean a test's
 * own configuration silently deciding which of a person's past attempts
 * counts as current. See `ExpireTrainingPasses`'s own docblock for the full
 * account, and `TrainingExpiryTest::test_when_taken_at_and_expires_on_disagree_taken_at_decides`
 * for the concrete case this class must keep agreeing with.
 *
 * A superseded attempt — one a LATER passed attempt for the same
 * (user_id, test_id) exists for — is never "lapsed" on its own account. Only
 * the current attempt's own `expires_on` is ever asked.
 */
final class LapsedPasses
{
    /**
     * Whether a LATER passed attempt exists for the same (user_id, test_id)
     * as $attempt — i.e. $attempt is not this person's current standing on
     * this test, and its own expiry (if any) is not news.
     *
     * `taken_at` first, `id` as the tiebreak — see this class's own
     * docblock. Copied verbatim out of `ExpireTrainingPasses::isSuperseded`
     * (task 10); that method now delegates here.
     */
    public static function isSuperseded(TrainingAttempt $attempt): bool
    {
        return TrainingAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where('test_id', $attempt->test_id)
            ->where('passed', true)
            ->where(function (Builder $query) use ($attempt) {
                $query->where('taken_at', '>', $attempt->taken_at)
                    ->orWhere(function (Builder $query) use ($attempt) {
                        $query->where('taken_at', $attempt->taken_at)
                            ->where('id', '>', $attempt->id);
                    });
            })
            ->exists();
    }

    /**
     * Every attempt that is a person's CURRENT standing on its test — the
     * latest passed attempt for that (user_id, test_id), never superseded
     * (see [isSuperseded]) — whatever its expiry.
     *
     * One query, not select-then-filter-in-PHP against [isSuperseded] per
     * candidate: a window function names the latest passed attempt per
     * (user_id, test_id) — `rn = 1`. This is the SAME fact
     * `isSuperseded()`/`ExpireTrainingPasses` compute per-candidate, phrased
     * as one set query instead of N existence checks — the shape
     * `DashboardController::index` and `LearnController::index` both need,
     * across many tests and many people, without turning into N+1. See
     * `DashboardController`'s own docblock for the measured query count
     * this keeps the Dashboard to.
     *
     * [currentlyLapsedQuery] is this query with one more predicate; both
     * read the SAME window-function subquery rather than each building
     * their own, which is what makes it impossible for the two to disagree
     * about which attempt is "current" while only one of them checks expiry.
     *
     * Narrowed to one test, several, or left open for every test the
     * caller's own query already scopes another way (`DashboardController`
     * passes every visible test's id at once; the Learn read and the people
     * pane each want one).
     *
     * @param  string|array<int, string>|null  $testIds
     */
    public static function currentStandingQuery(string|array|null $testIds = null): Builder
    {
        $latestPassed = TrainingAttempt::query()
            ->select(['id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id, test_id ORDER BY taken_at DESC, id DESC) AS rn')
            ->where('passed', true);

        if ($testIds !== null) {
            $latestPassed->whereIn('test_id', (array) $testIds);
        }

        return TrainingAttempt::query()
            ->joinSub($latestPassed, 'latest_passed', function ($join) {
                $join->on('training_attempts.id', '=', 'latest_passed.id');
            })
            ->where('latest_passed.rn', 1);
    }

    /**
     * [currentStandingQuery], kept to only the ones that have actually
     * lapsed: `expires_on` has reached or passed today.
     *
     * @param  string|array<int, string>|null  $testIds
     */
    public static function currentlyLapsedQuery(string|array|null $testIds = null): Builder
    {
        return self::currentStandingQuery($testIds)
            ->whereNotNull('training_attempts.expires_on')
            ->where('training_attempts.expires_on', '<=', now()->toDateString());
    }
}
