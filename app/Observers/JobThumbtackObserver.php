<?php

namespace App\Observers;

use App\Jobs\ReportThumbtackJobStatus;
use App\Models\Job;

/**
 * A job's status changed, so Thumbtack may need telling.
 *
 * ── Why an observer, in a codebase that has none ─────────────────
 *
 * Because a job's status changes down TWO paths, and this has to catch both.
 * `JobsController::update()` is the obvious one. `ActionRunner` is the other —
 * an automation rule can set a status, and the comment above its
 * SECONDARY_TRIGGERS says exactly why that matters: "an automated change must
 * emit them too, or 'when a job is completed' silently does not fire when
 * another rule is what completed it."
 *
 * That comment describes a duplication they were forced into. Adding a third
 * copy of "and also tell Thumbtack" to both call sites would inherit the same
 * problem and add a way for the two to drift — and a drift here is a Pro whose
 * completion record is right when a person clicks and wrong when a rule does.
 *
 * A model event fires on every path, including ones written later. It is one
 * file rather than a pattern: this is the only observer in the application, and
 * it exists because the alternative is two call sites in two other modules'
 * files.
 *
 * ── It decides almost nothing ────────────────────────────────────
 *
 * Whether the job is a Thumbtack one, whether its status maps to anything, and
 * what to send are all decided inside the queued job, where a tenant context
 * exists and a slow answer costs nobody a request. This only asks "did the
 * status change", which is the one question that must be asked here because the
 * dirty state is gone a moment later.
 */
final class JobThumbtackObserver
{
    public function updated(Job $job): void
    {
        // `wasChanged` and not `isDirty`: by `updated` the save has happened,
        // and isDirty would be empty.
        if (! $job->wasChanged('status')) {
            return;
        }

        if ($job->organization_id === null) {
            return;
        }

        ReportThumbtackJobStatus::dispatch(
            (string) $job->getKey(),
            (string) $job->organization_id,
            // NOW, carried into the job. Thumbtack asks for the moment the
            // status actually changed, and a retry running an hour later would
            // otherwise report the retry's own clock as the truth.
            now()->toIso8601String(),
        )->afterCommit();
    }
}
