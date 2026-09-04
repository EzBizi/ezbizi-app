<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TrainingAttempt;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

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
 * ── Idempotent by querying the outbox, not by a second column ─────
 *
 * "Running twice announces one lapse once" (the brief's own words) needs a
 * marker that a lapsed attempt has already been raised. A candidate is
 * skipped once an `automation_events` row already exists for it — `event_key
 * = 'training.expired'`, `subject_type = TrainingAttempt::class`,
 * `subject_id = $attempt->id` — rather than an `announced_at` column on
 * `training_attempts`. A column is a second copy of the same fact the
 * outbox already holds, and the two could disagree (a column set but the
 * insert rolled back, or the reverse); the outbox row IS the fact, so
 * querying it is asking the one place that can never be wrong about it.
 *
 * ── A superseded pass is not news ──────────────────────────────────
 *
 * If somebody retook the test and passed again after the lapsing attempt,
 * that later pass is the one the person is currently judged by — its own
 * `expires_on` (if any) is what will next matter, not the earlier one's.
 * Announcing the earlier lapse would tell a "re-assign when it lapses" rule
 * to hand back a guide the person has, in fact, already retaken. So a
 * candidate is skipped when a LATER passed attempt exists for the same
 * `(user_id, test_id)` — `isSuperseded()` below — and the search resumes
 * from that later attempt on ITS OWN merits the next time it, in turn,
 * lapses.
 *
 * ── Scale ───────────────────────────────────────────────────────────
 *
 * Nightly, across every tenant, so nothing here loads every attempt into
 * memory. The candidate scan reads `training_attempts` through the
 * `(organization_id, expires_on)` index the migration already carries
 * (`passed` and `expires_on IS NOT NULL` are cheap filters applied to the
 * small result that index produces, not a table scan), and `--limit` bounds
 * how many candidates one run pulls into memory at all — 500 by default, the
 * same figure `ezbizi:dispatch-task-overdue` uses for the same reason.
 * `isSuperseded()` and the outbox check are both point lookups against
 * indexed columns (`training_attempts` carries `(organization_id, test_id,
 * user_id)`; `automation_events` is looked up by `subject_id`), so 100,000
 * attempts costs one indexed range scan plus two small indexed lookups per
 * candidate that survives the NOT EXISTS filter — not per row in the table.
 */
final class ExpireTrainingPasses extends Command
{
    protected $signature = 'ezbizi:expire-training-passes {--limit=500}';

    protected $description = 'Raise training.expired for training passes that have lapsed, once each';

    public function handle(TenantContext $tenants, EventEmitter $events): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));

        // Genuinely cross-tenant: this is the platform-wide sweep, never one
        // organization's own request. runWithoutTenant() is the named way to
        // say a read spans every tenant on purpose (see TenantScope's
        // docblock) — the same call ExpireTaskUpdateAttachmentCandidates
        // makes for the identical reason.
        $candidates = $tenants->runWithoutTenant(fn () => TrainingAttempt::query()
            ->where('passed', true)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->toDateString())
            // Filtered here, at the DB, rather than after fetching: without
            // this, a backlog larger than --limit would keep reselecting the
            // same already-announced rows forever and never reach the rest.
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('automation_events')
                    ->whereColumn('automation_events.subject_id', 'training_attempts.id')
                    ->where('automation_events.subject_type', TrainingAttempt::class)
                    ->where('automation_events.event_key', 'training.expired');
            })
            ->orderBy('training_attempts.expires_on')
            ->orderBy('training_attempts.id')
            ->limit($limit)
            ->get(['id', 'organization_id', 'user_id', 'test_id', 'taken_at', 'expires_on']));

        $announced = 0;

        foreach ($candidates as $attempt) {
            $announced += $this->announce($tenants, $events, $attempt);
        }

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

        if ($organization === null || $organization->isReadOnly()) {
            return 0;
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
        if ($this->isSuperseded($attempt) || $this->alreadyAnnounced($attempt)) {
            return 0;
        }

        /*
         * INSERTS, never dispatches (StartUp §10). One rule failing while
         * re-assigning one lapsed ladder-safety pass must not take the sweep
         * down for everybody else's — which is exactly what an inline
         * dispatch inside this loop would do.
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

    /** The idempotence check itself — see this class's own docblock. */
    private function alreadyAnnounced(TrainingAttempt $attempt): bool
    {
        return AutomationEvent::query()
            ->where('subject_type', TrainingAttempt::class)
            ->where('subject_id', $attempt->id)
            ->where('event_key', 'training.expired')
            ->exists();
    }
}
