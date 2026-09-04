<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TrainingAttempt;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The nightly sweep that notices a training pass has run out (task 10 brief).
 *
 * `LearnController::submit` stamps `expires_on` on a PASSED attempt when its
 * test carries `valid_for_days`. Nothing watches the clock after that — this
 * is the thing that does, and `training.expired` is what lets an
 * organization write the rule the option exists for: "when the ladder-safety
 * pass runs out, assign the guide again."
 *
 * ── INSERT, never dispatch (StartUp.md §10) ───────────────────────
 *
 * `EventEmitter::emit` INSERTS an `automation_events` row; it never
 * dispatches. That matters most here of any write path in this module: one
 * rule failing while re-assigning one lapsed pass must not take the sweep
 * down for everybody else's, which is exactly what an inline dispatch inside
 * this loop would do. `ezbizi:dispatch-automation-events` is the separate,
 * scheduled reader that turns the row into a real delivery, a minute later
 * at most.
 *
 * ── Idempotent by a column, not by querying the outbox ────────────
 *
 * "Running twice announces one lapse once" (the brief's own words) needs a
 * marker that survives at least as long as a lapsed pass can go unretaken —
 * which, in this codebase, can be forever. The FIRST version of this command
 * queried `automation_events` for an existing `training.expired` row instead
 * of adding a column, on the reasoning that a column would be a second copy
 * of a fact the outbox already held. That reasoning assumed the outbox row
 * survives, and it does not: `AutomationEvent::retention()` is a 3-month
 * window, and `ExpireData` hard-deletes every delivered row past it — which
 * this sweep's own events always become within a minute
 * (`ezbizi:dispatch-automation-events` runs every minute). Proven in review:
 * emit, delete the row, re-run — a SECOND `training.expired` landed for the
 * same attempt. Left alone, that is not a one-off bug: `ezbizi:expire-data`
 * runs nightly at 03:50 and this sweep at 04:10, so it re-announces every
 * lapsed, never-retaken pass every three months, forever. See the migration
 * that adds `training_attempts.expiry_announced_at`
 * (`2026_09_04_090000_add_expiry_announced_at_to_training_attempts.php`) for
 * the full account. The outbox is deliberately temporary; a fact that must
 * outlive it needs a column that does.
 *
 * The candidate query filters `whereNull('expiry_announced_at')` — this is
 * THE guard, not a belt-and-braces double-check, and it is load-bearing for
 * two different reasons at once: without it, a backlog would keep
 * re-selecting the same already-announced rows every run and never advance
 * past them, and a first run of this command would announce every lapse in
 * the table's entire history, because there would be nothing to bound it
 * below. `announceWithinTenant()` sets the column in the SAME transaction as
 * the `emit()` call, so the two cannot disagree — either both happen or
 * neither does. There is deliberately no second, in-loop re-check of the
 * column before writing: an earlier version had one, and nothing in the
 * suite could tell the two guards apart (removing either alone still left
 * every test green). Nothing schedules this command to run more than once a
 * day and the scheduled entry carries no `withoutOverlapping()` either,
 * matching the two other `runWithoutTenant` sweeps in `routes/console.php`
 * (`ezbizi:expire-task-import-sessions`, `ezbizi:expire-task-update-
 * attachment-candidates`) — neither guards against an overlapping run of
 * itself. If this command is ever scheduled more than once a day, that
 * decision needs revisiting together with a lock, not answered by a second
 * copy of a check this file already makes once.
 *
 * ── A superseded pass is not news ──────────────────────────────────
 *
 * If somebody retook the test and passed again after the lapsing attempt,
 * that later pass is the one the person is currently judged by — its own
 * `expires_on` (if any) is what will next matter, not the earlier one's. So
 * a candidate is skipped when a LATER passed attempt exists for the same
 * `(user_id, test_id)` — `isSuperseded()` below — and the search resumes
 * from that later attempt on ITS OWN merits the next time it, in turn,
 * lapses.
 *
 * `isSuperseded()` compares `taken_at`, not `expires_on`, and the two CAN
 * disagree about which attempt is "later" — see
 * `test_when_taken_at_and_expires_on_disagree_taken_at_decides` in the test
 * file for the concrete case. `taken_at` is the deliberate choice: it is when
 * the person actually sat the test, which is what "the person's current
 * standing" means, and `expires_on` is only a function of that plus a test's
 * `valid_for_days` at the time it was taken — a later `valid_for_days` change
 * on the test can move `expires_on` without the person having done anything
 * new. Following `expires_on` instead would mean a test's own configuration
 * change silently deciding which of a person's past attempts counts as
 * current.
 *
 * ── isReadOnly() is deliberately NOT checked here ──────────────────
 *
 * An earlier version skipped a candidate's organization when
 * `Organization::isReadOnly()`. Untested, not in the brief, and neither
 * sibling `runWithoutTenant` sweep in this file's own routes/console.php
 * neighbourhood checks it either. It was also redundant with a guard that
 * already exists: `AutomationEngine::deliver()` checks `isReadOnly()` itself
 * before running any rule against a delivered event, and reports why — "Not
 * run — this organization is read-only." Raising the outbox row here for a
 * read-only organization costs one row nobody will ever act on and changes
 * nothing about billing or access; refusing to raise it here would just move
 * the same check to a second place with no test pinning either copy.
 *
 * ── Scale, measured ─────────────────────────────────────────────────
 *
 * `EXPLAIN` against the ORIGINAL query (no `organization_id` predicate —
 * this runs `runWithoutTenant()` on purpose — and `automation_events` looked
 * up by `subject_id`, a column no index on that table contains) showed a
 * full scan on both sides: `training_attempts` came back `type: ALL,
 * possible_keys: null, Using temporary; Using filesort`, and
 * `automation_events` came back `type: ALL, possible_keys: null` in a hash
 * join. `possible_keys: null` means no index was even a candidate.
 *
 * Two fixes, not one. First, the `automation_events` side of the query is
 * simply gone — the idempotency check no longer touches that table at all
 * (see above), so there is nothing left there to index or scan. Second,
 * `training_attempts_unannounced_lapse_index` on `(passed,
 * expiry_announced_at, expires_on)` — added by this task's migration — is
 * built for exactly the predicates this query always carries: two
 * equalities (`passed = 1`, `expiry_announced_at IS NULL`) leading a range
 * (`expires_on <= ?`).
 *
 * `EXPLAIN` against the query as it stands NOW, run against
 * `ezbizi_testing_training` seeded with 2,000 rows in one organization (a mix
 * of not-passed, not-yet-lapsed, and already-announced rows alongside 260
 * genuine candidates) — measured, not claimed:
 *
 *   select_type: SIMPLE   table: training_attempts   type: range
 *   possible_keys: training_attempts_unannounced_lapse_index
 *   key: training_attempts_unannounced_lapse_index   key_len: 10   ref: NULL
 *   rows: 266   filtered: 100.00   Extra: Using index condition
 *
 * — `type: range` on the new index, not `ALL`; `possible_keys` names it (it
 * was `null` before); `rows: 266` against 260 actual matches, not the full
 * 2,000; no `Using temporary; Using filesort`. See the task-10 fix report for
 * the literal seeded run that produced this.
 *
 * Per candidate that survives the index (a small set on any ordinary night —
 * only attempts that newly lapsed since the last run), `isSuperseded()` is a
 * point lookup on `(organization_id, test_id, user_id)`, a prefix of the
 * migration's own index. Rows are pulled through `lazyById()` in chunks of
 * 500 rather than materialised all at once, so the whole backlog drains in
 * one run — however large — with memory bounded by the chunk size rather
 * than by a `--limit` a forgotten backlog could silently strand behind (see
 * the option's removal, task-10 review round 2).
 */
final class ExpireTrainingPasses extends Command
{
    protected $signature = 'ezbizi:expire-training-passes';

    protected $description = 'Raise training.expired for training passes that have lapsed, once each';

    public function handle(TenantContext $tenants, EventEmitter $events): int
    {
        $announced = 0;

        $tenants->runWithoutTenant(function () use ($tenants, $events, &$announced) {
            TrainingAttempt::query()
                ->where('passed', true)
                ->whereNotNull('expires_on')
                ->where('expires_on', '<=', now()->toDateString())
                // The one idempotency guard — see this class's own docblock.
                ->whereNull('expiry_announced_at')
                ->select(['id', 'organization_id', 'user_id', 'test_id', 'taken_at', 'expires_on'])
                ->lazyById(500)
                ->each(function (TrainingAttempt $attempt) use ($tenants, $events, &$announced) {
                    $announced += $this->announce($tenants, $events, $attempt);
                });
        });

        $this->info($announced === 0
            ? 'No lapsed training passes to announce.'
            : "Announced {$announced} lapsed training pass(es).");

        return self::SUCCESS;
    }

    /**
     * One candidate, one organization entered, one failure contained.
     *
     * Same shape as `TaskDueWorker::runCandidate` (task 5/6): a candidate's
     * uncaught exception is reported and counted as zero rather than
     * aborting the rest of the batch, and the candidate itself is untouched
     * — no marker is written on failure — so it is picked up again on the
     * next run.
     */
    private function announce(TenantContext $tenants, EventEmitter $events, TrainingAttempt $attempt): int
    {
        $organization = Organization::query()->find($attempt->organization_id);

        if ($organization === null) {
            return 0;   // the organization was removed between the scan and this candidate
        }

        try {
            return $tenants->run($organization, fn () => $this->announceWithinTenant($events, $attempt));
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function announceWithinTenant(EventEmitter $events, TrainingAttempt $attempt): int
    {
        if ($this->isSuperseded($attempt)) {
            return 0;
        }

        DB::transaction(function () use ($events, $attempt) {
            /*
             * INSERTS, never dispatches (StartUp §10). One rule failing while
             * re-assigning one lapsed ladder-safety pass must not take the
             * sweep down for everybody else's — which is exactly what an
             * inline dispatch inside this loop would do.
             *
             * The payload carries only what the published trigger description
             * promises — a pass reaching the end of its period, and that it
             * reappears as due for retaking. It never repeats `score` or
             * `answers`: `training_attempts` holds both (§9 ACTIVITY personal
             * data), a `webhook` action ships this payload verbatim to a
             * tenant-chosen URL, and neither the score nor a named person's
             * individual answers is a fact the "pass ran out" sentence ever
             * promised to carry — the same boundary LearnController::submit
             * draws around `training.passed` / `training.failed`.
             */
            $events->emit('training.expired', $attempt, [
                'after' => [
                    'test_id' => $attempt->test_id,
                    'user_id' => $attempt->user_id,
                    'expires_on' => $attempt->expires_on->toDateString(),
                ],
            ]);

            // Same transaction as the emit — see this class's own docblock on
            // why a column, and why it must not be set (or left unset) on its
            // own.
            $attempt->forceFill(['expiry_announced_at' => now()])->save();
        });

        return 1;
    }

    /**
     * Whether a LATER passed attempt exists for this same person and test.
     *
     * `taken_at` first, `id` as the tiebreak: `training_attempts.id` is an
     * ordered UUID (Laravel's `HasUuids` default), so two attempts recorded
     * within the same clock second still compare in creation order. An
     * arbitrary but STABLE choice for that edge case, the same shape
     * `LearnController::presentAssignment` uses for its own tie.
     *
     * Compares `taken_at`, not `expires_on` — the two can disagree about
     * which attempt is "later" (see this class's own docblock and
     * `test_when_taken_at_and_expires_on_disagree_taken_at_decides`).
     */
    private function isSuperseded(TrainingAttempt $attempt): bool
    {
        return TrainingAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where('test_id', $attempt->test_id)
            ->where('passed', true)
            ->where(function ($query) use ($attempt) {
                $query->where('taken_at', '>', $attempt->taken_at)
                    ->orWhere(function ($query) use ($attempt) {
                        $query->where('taken_at', $attempt->taken_at)
                            ->where('id', '>', $attempt->id);
                    });
            })
            ->exists();
    }
}
