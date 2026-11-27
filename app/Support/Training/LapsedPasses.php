<?php

namespace App\Support\Training;

use App\Models\TrainingAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            ->where('training_attempts.expires_on', '<=', TrainingDay::today());
    }

    // ── Fifth-option brief (2026-09-09): which attempts count toward the cap ──

    /**
     * The number to compare against `max_attempts` when the test's own
     * `count_attempts_after_pass` is `false` — NOT simply a historical
     * count. Built by walking every existing attempt oldest-first and
     * excluding one taken while an EARLIER passed attempt for the same
     * (user, test) was still valid, i.e. not lapsed (see
     * [currentlyLapsedQuery]'s own "`<=` today" boundary — the same
     * "expires_on has reached or passed" line, just asked at the earlier
     * attempt's own `taken_at` instead of at `now()`) — and reporting 0
     * whenever the person CURRENTLY holds a still-valid pass, whatever that
     * walk's own running count reached; see [walkCountingTowardCap]'s own
     * docblock for why a caller must never compare the raw historical count
     * instead.
     *
     * The owner's report: someone who already passed and retakes for a
     * better score "burns an attempt from an allowance meant for people who
     * failed." A `false` test's cap is meant only for people who have not
     * yet cleared it — so a retake sat while a pass from an EARLIER attempt
     * is still good does not spend a slot, whatever the retake's own
     * outcome. Once that earlier pass itself lapses, the shelter it gave is
     * gone: an attempt sat after that counts normally again, the same as if
     * no pass had ever been recorded — see this class's own docblock,
     * "`taken_at`, never `expires_on`, decides `current`", for why an
     * attempt's OWN place in this count can never move once it exists: the
     * fact it is measured against (an earlier row's fixed `taken_at` and
     * `expires_on`) never changes after that earlier row is written.
     *
     * ── The failures-only ruling (owner, 2026-09-09) ────────────────────
     *
     * A later review found the fifth option still created the exact
     * permanent dead end this module's own rules forbid: with
     * `max_attempts: 1`, the PASSING attempt itself used to spend the one
     * slot — nothing precedes the first attempt to shelter it — and the
     * end-of-walk override below only masks that miscount for as long as
     * the pass stays current. The moment it lapsed, the raw count was
     * exposed and the learner could never sit the test again. The owner's
     * ruling: an attempt counts toward `max_attempts` only if it BOTH (1)
     * failed and (2) was not taken while an earlier pass was still valid.
     * Neither condition alone closes the hole — a passing attempt must
     * never count (that is this fix), and a FAILING attempt taken under a
     * still-good pass must not count either, or the identical lockout
     * reappears one call later: pass, retake-while-valid-and-fail (that
     * failure alone spends the slot under a "failures only" reading), the
     * pass lapses, and the learner is back at the cap with nothing valid to
     * shelter behind. [walkCountingTowardCap] below applies both.
     *
     * `LearnController::submit` asks this BEFORE inserting the attempt under
     * test — the count is of attempts that already exist, exactly like the
     * plain `COUNT(*)` it replaces when the option is off. `showTest` asks
     * it for the same reason [attemptCounts] exists at all (that method's
     * own docblock): so a client gating Submit on `max_attempts` is never
     * shown a smaller number of "used" attempts than the server will
     * actually enforce.
     *
     * ── The per-cycle ruling (owner, 2026-09-09, one step past the
     *    failures-only ruling above) ─────────────────────────────────────
     *
     * A 128-scenario search (every 4-intent pass/fail sequence × `max_attempts`
     * {1, 2} × `valid_for_days` {10, null} × the option {off, on}, with time
     * travel between submissions) proved the failures-only ruling above still
     * had a lifetime lockout in it: `$counted` never forgot a failure just
     * because the learner went on to pass again. `fail` (spends a slot),
     * `pass` (re-certifies — a closed, spent cycle by every rule already in
     * this class), `fail` again after THAT pass lapses — under a plain
     * lifetime sum this is 2 failures against `max_attempts: 2`, refused,
     * forever, even though the learner is one cycle into a fresh
     * certification and has failed it exactly ONCE. A stale failure from a
     * cycle the learner already closed by re-certifying was still being held
     * against them.
     *
     * The owner's ruling: the cap is per certification cycle, not per
     * lifetime. Counting starts again at each landed pass — only attempts
     * SINCE the learner's most recent passing attempt can count toward
     * `max_attempts`; everything before that pass is a closed cycle and is
     * spent, exactly like a failure sheltered by a still-valid pass is spent
     * the moment that pass lands (this class's own "failures-only ruling"
     * above; per-cycle is a THIRD narrowing on top of the same two
     * conditions, never a replacement for either). [walkCountingTowardCap]
     * applies it by resetting `$counted` to 0 at every landed pass, in the
     * same pass at which `$holding`/`$expiresOn` already reset — a pass is
     * always this person's new current standing, and now also always the
     * start of a new counting window.
     *
     * This does NOT manufacture a recovery path out of a cycle that is
     * itself still open: a learner who has genuinely spent every failure
     * `max_attempts` allows since their OWN last pass, without landing a new
     * one before hitting the cap, is refused until the author raises the
     * cap — identical in kind to `max_attempts: 1` refusing a first-ever
     * failure with no pass on record at all (this class's own prior "Trying
     * to find a path this does not close" report, corrected here: that
     * report's search never asked what happens ACROSS cycles, only within
     * one, which is exactly the gap this ruling closes). What per-cycle
     * closes is a stale, ALREADY-superseded cycle's spent failures reaching
     * across a genuine re-certification to refuse a learner who is only one
     * failure into their current, open cycle.
     */
    public static function attemptsCountingTowardCap(string $userId, string $testId): int
    {
        $attempts = TrainingAttempt::query()
            ->where('user_id', $userId)
            ->where('test_id', $testId)
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get(['id', 'taken_at', 'passed', 'expires_on']);

        return self::walkCountingTowardCap($attempts);
    }

    /**
     * [attemptsCountingTowardCap], batched across every test in $testIds for
     * ONE user — one query, not one per test. `LearnController::attemptCounts`
     * is the one caller: it already knows, per test, whether
     * `count_attempts_after_pass` is on or off, and asks this only for the
     * `off` subset — the `on` subset stays a plain grouped `COUNT(*)`, which
     * is cheaper and needs none of this.
     *
     * @param  array<int, string>  $testIds
     * @return Collection<string, int>
     */
    public static function attemptsCountingTowardCapBatch(string $userId, array $testIds): Collection
    {
        if ($testIds === []) {
            return collect();
        }

        $attempts = TrainingAttempt::query()
            ->where('user_id', $userId)
            ->whereIn('test_id', $testIds)
            ->orderBy('test_id')
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get(['id', 'test_id', 'taken_at', 'passed', 'expires_on']);

        // groupBy preserves each group's original (already-ordered) sequence
        // — see Laravel's own contract for Collection::groupBy — so every
        // group [walkCountingTowardCap] receives is still taken_at/id order,
        // exactly what [attemptsCountingTowardCap] gives it one test at a
        // time.
        return $attempts->groupBy('test_id')->map(fn (Collection $group) => self::walkCountingTowardCap($group));
    }

    /**
     * The one walk both [attemptsCountingTowardCap] and its batched sibling
     * run: $orderedAttempts for a SINGLE (user, test) pair, oldest first.
     *
     * `$holding`/`$expiresOn` track whether, at the CURRENT attempt's own
     * `taken_at`, an earlier passed attempt in this same walk is still
     * valid — `$expiresOn === null` meaning that pass never expires at all.
     * A later PASSED attempt always replaces them, whether or not that
     * later attempt itself counted, because it is now this person's own
     * current standing (the same "latest passed attempt wins" rule
     * [currentStandingQuery] applies) — a failed attempt never touches
     * either variable, since holding a still-good pass is not undone by
     * failing a retake of it.
     *
     * ── What actually increments $counted (owner's ruling, 2026-09-09) ──
     *
     * Only a FAILED attempt taken while NOT sheltered by a still-valid
     * earlier pass (`! $stillValid`) — a passing attempt never increments
     * $counted, whatever $stillValid says, because a pass is never itself
     * the thing `max_attempts` is meant to ration (this class's own
     * "owner's report", above). `$holding`/`$expiresOn` are still updated
     * off every PASSED attempt regardless of whether that attempt counted —
     * a pass always becomes the person's new current standing — and are
     * left untouched by a failed one, sheltered or not: holding a still-good
     * pass is not undone by failing a retake of it.
     *
     * ── The per-cycle reset (owner's ruling, 2026-09-09) ────────────────
     *
     * `$counted` is ALSO reset to 0 in that same "the attempt passed" branch
     * — the identical instant `$holding`/`$expiresOn` reset to describe this
     * person's new current standing. A landed pass closes the cycle every
     * failure counted before it belonged to; nothing from before it is owed
     * against this person's NEXT cycle's own `max_attempts` budget, however
     * many failures that closed cycle held. This is on top of, never instead
     * of, the sheltering exclusion just above: a failure sat WHILE sheltered
     * was already excluded at the point it was taken (`! $stillValid` never
     * true for it, so $counted never rose for it in the first place); this
     * reset is what stops an UNSHELTERED failure from an earlier, since-
     * closed cycle from still being held on the books once a new pass has
     * genuinely re-certified the person. See this class's own
     * `attemptsCountingTowardCap` docblock, "The per-cycle ruling", for the
     * lockout this closes and the one shape of lockout it deliberately does
     * NOT — a learner's own currently OPEN cycle exhausting its real budget
     * with no new pass yet on record stays refused, same as it would for
     * anyone who has never passed at all.
     *
     * ── Why the return value is 0, not the historical count, whenever the
     *    walk ends still holding ────────────────────────────────────────
     *
     * Both callers ask this to answer ONE question — "is a NEW attempt,
     * about to be taken right now, blocked by `max_attempts`?" — never "how
     * many attempts has this person made." A learner currently holding a
     * still-valid pass (the loop's own final `$holding`/`$expiresOn`, asked
     * against `now()` — the `taken_at` a new attempt would get) may always
     * take one more for free, however much of the real budget history is
     * already spent, so this reports 0: safe against ANY `max_attempts`
     * (`min:1` — `TestsController::rules`), and true to "does not count
     * against max_attempts" (this class's own `attemptsCountingTowardCap`
     * docblock) rather than merely "counts one fewer." This override is
     * NOT what makes a passing attempt free of the cap any more — $counted
     * itself never rises for one now — but it is still what a failing
     * retake taken while currently sheltered needs: that failure is
     * excluded from $counted by `$stillValid` at the point it was taken,
     * and this override is what keeps a NEXT attempt admitted too, without
     * which a stale pre-lapse $counted could still wrongly block it.
     *
     * @param  iterable<int, TrainingAttempt>  $orderedAttempts
     */
    private static function walkCountingTowardCap(iterable $orderedAttempts): int
    {
        $counted = 0;
        $holding = false;
        $expiresOn = null;

        foreach ($orderedAttempts as $attempt) {
            $stillValid = $holding && ($expiresOn === null || $expiresOn->toDateString() > $attempt->taken_at->toDateString());

            if (! $stillValid) {
                $holding = false;

                if (! $attempt->passed) {
                    $counted++;
                }
            }

            if ($attempt->passed) {
                $holding = true;
                $expiresOn = $attempt->expires_on;
                $counted = 0; // Per-cycle ruling: a landed pass closes the prior cycle.
            }
        }

        // No end-of-walk override, and its absence is the point.
        //
        // Before the per-cycle ruling this returned `$currentlyValid ? 0 :
        // $counted`, to stop a learner who holds a valid pass being refused by
        // failures counted earlier. Per-cycle counting SUBSUMES it: every path
        // to "holding a valid pass now" runs through a passing attempt, and a
        // pass zeroes `$counted` above — while a failure taken WHILE holding is
        // sheltered and never increments. So `$counted > 0` and currently-valid
        // cannot both be true, and the override could only ever return the value
        // it was already returning.
        //
        // Measured, not assumed: with the override still in place, deleting it
        // left TrainingGradingTest 34/34 green, including the case written to
        // catch exactly it (a learner holding a valid pass with failures on
        // record). A line no input can reach and no test can kill is dead code,
        // and a docblock calling it load-bearing is worse than no docblock.
        return $counted;
    }
}
