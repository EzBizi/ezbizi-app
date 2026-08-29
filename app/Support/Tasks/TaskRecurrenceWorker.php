<?php

namespace App\Support\Tasks;

use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskCollaborator;
use App\Models\TaskColumnLabel;
use App\Models\TaskColumnPerson;
use App\Models\TaskColumnValue;
use App\Models\TaskRecurrenceOccurrence;
use App\Models\TaskStatusOption;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates the next instance of a recurring task series (Tasks Phase 2 design
 * §11, task-6 brief). Built on the same shape as TaskDueWorker: bounded
 * batches, board-place timezone through SettingsResolver, and an idempotency
 * row (TaskRecurrenceOccurrence) written in the same transaction as whatever
 * it records — either a created task, or a terminal marker with no task.
 *
 * ── The two modes ─────────────────────────────────────────────────
 *
 * `on_schedule` is purely calendar-driven: onSchedule() walks forward from
 * the series task's own start_on/due_on, one `every`/`unit` step per cycle,
 * and creates every due-and-not-yet-recorded occurrence regardless of
 * whether earlier ones were finished. It runs on the hourly schedule.
 *
 * `after_completion` is completion-driven: exactly one occurrence is ever
 * pending at a time, dated `every`/`unit` from the completion that produced
 * it. TaskWriter does NOT call into this class at completion time (fix
 * round 1, task-6): it only emits `RELAY_TRIGGER` to the outbox — a plain
 * `EventEmitter::emit()`, no lock, no task creation, nothing that can abort
 * the completion's own transaction. relayPendingCompletions() is this
 * class's hourly consumer of that marker; it does the lock-heavy
 * buildInstance() work in its OWN transaction, not the user's.
 *
 * Why not call straight into this class from TaskWriter (the original
 * round-1 design): buildInstance()'s nextPosition() takes
 * `lockForUpdate()->max('position')`. An InnoDB deadlock there aborts the
 * WHOLE transaction, not a savepoint — a `catch (\Throwable)` around the
 * call still fires, but the outer COMMIT that follows lands on a
 * transaction the server already discarded, silently losing the
 * completion itself while the API reports success. Routing through the
 * outbox means the completion transaction never touches a lock it doesn't
 * already need for its own sake.
 *
 * relayPendingCompletions() reads a bounded, RECENT window of
 * RELAY_TRIGGER events rather than scanning "every completed recurring
 * task" (which never shrinks — see the task-6 report's first design
 * write-up for why that scan is unsafe). It re-reads each task's CURRENT
 * `completed_at` rather than trusting anything in the event payload, so
 * reprocessing the same marker (a missed run, a retried run, two
 * overlapping runs) is exactly as safe as the periodic `on_schedule` scan:
 * idempotent through attemptOccurrence()'s occurrence-uniqueness, not
 * through anything about the event itself.
 *
 * Both modes share attemptOccurrence(): a task_recurrence_occurrences row
 * is WrittenOnce (immutable), so there is no two-phase "claim, then fill in
 * created_task_id" — the row is inserted complete, in the same transaction
 * as the task it names, or not inserted at all. A retried or overlapping
 * attempt at the same (task_id, occurrence_date) either short-circuits on
 * the pre-check or loses the race to the unique index and rolls back
 * cleanly; either way nothing duplicate survives.
 */
final class TaskRecurrenceWorker
{
    /** Safety valve on a single on_schedule series per run — a real
     * declaration is bounded by `until`/`count` long before this matters. */
    private const MAX_CYCLES_PER_SERIES = 500;

    /**
     * The outbox marker TaskWriter emits at completion (fix round 1). Named
     * as a real, tenant-legible fact ("this task's next recurrence instance
     * is due"), the same way every other row in this outbox is both
     * infrastructure and something a tenant could build a rule on — there
     * is no "internal-only" trigger concept in this registry
     * (EventEmitter::assertRegistered() has no such carve-out).
     */
    public const RELAY_TRIGGER = 'task.recurrence_instance_due';

    /**
     * How far back relayPendingCompletions() looks for RELAY_TRIGGER
     * events. Bounded and self-shrinking — old rows simply age out of the
     * window forever, the same shape as TaskDueWorker's reminder lookback —
     * rather than the ever-growing "every completed recurring task" scan
     * this replaces. Three days is generous against an hourly cadence: it
     * absorbs a missed run or two without a real outage silently losing a
     * relay forever.
     */
    private const RELAY_WINDOW_HOURS = 72;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly RecordNumberGenerator $numbers,
    ) {}

    /**
     * The hourly sweep for `on_schedule` series. `after_completion` series
     * are handled by relayPendingCompletions() instead, and are excluded
     * here at the SQL level (`recurrence->mode`), not by loading each task
     * and rejecting it in PHP — the same reason TaskDueWorker excludes
     * Completed/Cancelled tasks in its own WHERE clause rather than after
     * the fact: a mode check applied in PHP only after LIMIT has already
     * bounded the batch lets one mode crowd the other out of the window
     * instead of actually bounding "how much on_schedule work happens".
     */
    public function onSchedule(int $limit = 500): int
    {
        $created = 0;
        $query = Task::withoutGlobalScopes()
            ->where('recurrence->mode', 'on_schedule')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 5000)));

        foreach ($query->pluck('organization_id', 'id') as $taskId => $organizationId) {
            $organization = Organization::query()->find($organizationId);
            if ($organization === null || $organization->isReadOnly()) {
                continue;
            }
            $created += $this->tenant->run($organization, function () use ($taskId): int {
                $task = Task::query()->active()->with(['board.company', 'board.franchise', 'board.organization'])
                    ->find($taskId);
                if ($task === null || $task->board === null || $task->board->archived_at !== null) {
                    return 0;
                }
                $recurrence = TaskRecurrenceCodec::parse($task->recurrence);
                if ($recurrence === null || $recurrence->mode !== 'on_schedule') {
                    return 0;
                }

                return $this->advanceSchedule($task, $recurrence);
            });
        }

        return $created;
    }

    /**
     * The hourly consumer of RELAY_TRIGGER (fix round 1, task-6):
     * TaskWriter emits that marker, cheaply, at the moment an
     * `after_completion` task completes; this is where the actual
     * lock-heavy work — checking exhaustion, building the next instance —
     * happens instead, in its own transaction rather than the user's.
     *
     * Deliberately re-reads the task's CURRENT `completed_at` rather than
     * anything carried in the event: if the task was reopened again before
     * this ran, `completed_at` is null and there is nothing to do, exactly
     * matching "creates nothing while the task stays open". If the SAME
     * task was completed, reopened, and completed again before this ran,
     * both markers converge on the SAME current `completed_at` and the
     * second attempt is a no-op via attemptOccurrence()'s own uniqueness —
     * not because of anything special done here.
     */
    public function relayPendingCompletions(int $limit = 500): int
    {
        $created = 0;
        $query = AutomationEvent::withoutGlobalScopes()
            ->where('event_key', self::RELAY_TRIGGER)
            ->where('occurred_at', '>=', now()->subHours(self::RELAY_WINDOW_HOURS))
            ->orderBy('occurred_at')
            ->limit(max(1, min($limit, 5000)));

        foreach ($query->get(['organization_id', 'subject_id']) as $event) {
            $organization = Organization::query()->find($event->organization_id);
            if ($organization === null || $organization->isReadOnly()) {
                continue;
            }
            $created += $this->tenant->run($organization, function () use ($event): int {
                $task = Task::query()->active()->with(['board.company', 'board.franchise', 'board.organization'])
                    ->find($event->subject_id);
                if ($task === null || $task->board === null || $task->board->archived_at !== null
                    || $task->completed_at === null) {
                    return 0;
                }
                $recurrence = TaskRecurrenceCodec::parse($task->recurrence);
                if ($recurrence === null || $recurrence->mode !== 'after_completion') {
                    return 0;
                }
                // The task that just completed may itself be a later
                // generation (it carries `recurrence` forward — see
                // buildInstance()). Occurrence bookkeeping (uniqueness,
                // until/count) is keyed on the ORIGINAL series root across
                // every generation, not on whichever task happened to
                // complete — otherwise `count` would silently reset to zero
                // every generation and never actually stop the series.
                // Copying FIELDS (title, owner, ...) still comes from $task
                // itself, the most recently completed generation.
                $seriesRootId = $this->seriesRootId($task->id);
                if ($this->seriesEnded($seriesRootId)) {
                    return 0;
                }

                $completionDate = $task->completed_at->copy()
                    ->setTimezone($this->timezone($task))->toDateString();
                $nextDate = $this->step($completionDate, $recurrence, 1);
                $cycle = (int) TaskRecurrenceOccurrence::query()
                    ->where('task_id', $seriesRootId)->whereNotNull('created_task_id')->count() + 1;

                return $this->attemptOccurrence($task, $seriesRootId, $recurrence, $nextDate, $cycle) === 'created' ? 1 : 0;
            });
        }

        return $created;
    }

    /**
     * Walks `created_task_id -> task_id` backward through
     * task_recurrence_occurrences until it reaches a task nothing produced
     * — the original, human-declared series root. Strictly finite: each
     * step moves to a chronologically earlier occurrence, so there is no
     * cycle to walk into.
     */
    private function seriesRootId(string $taskId): string
    {
        $currentId = $taskId;
        while (true) {
            $producedByRootId = TaskRecurrenceOccurrence::query()
                ->where('created_task_id', $currentId)->value('task_id');
            if ($producedByRootId === null) {
                return $currentId;
            }
            $currentId = $producedByRootId;
        }
    }

    private function advanceSchedule(Task $root, TaskRecurrence $recurrence): int
    {
        // on_schedule never chains through completion (instances don't
        // carry recurrence forward), so the root scanned here is always
        // already the series root — no seriesRootId() walk needed.
        if ($this->seriesEnded($root->id)) {
            return 0;
        }
        $baseline = $root->start_on?->format('Y-m-d') ?? $root->due_on?->format('Y-m-d');
        if ($baseline === null) {
            return 0;
        }
        $today = Carbon::now($this->timezone($root))->toDateString();

        $created = 0;
        for ($cycle = 1; $cycle <= self::MAX_CYCLES_PER_SERIES; $cycle++) {
            $date = $this->step($baseline, $recurrence, $cycle);
            if ($date > $today) {
                break;
            }
            $result = $this->attemptOccurrence($root, $root->id, $recurrence, $date, $cycle);
            if ($result === 'terminal') {
                break;
            }
            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  Task  $fieldsSource  the task whose fields the new instance
     *                              copies — the one that just completed (or,
     *                              for on_schedule, the series root itself).
     * @param  string  $seriesRootId  the stable identity occurrence
     *                                bookkeeping is keyed on — see
     *                                seriesRootId().
     * @return 'created'|'terminal'|'skipped'
     */
    private function attemptOccurrence(Task $fieldsSource, string $seriesRootId, TaskRecurrence $recurrence, string $date, int $cycle): string
    {
        try {
            return DB::transaction(function () use ($fieldsSource, $seriesRootId, $recurrence, $date, $cycle): string {
                $exists = TaskRecurrenceOccurrence::query()
                    ->where('task_id', $seriesRootId)->where('occurrence_date', $date)->exists();
                if ($exists) {
                    return 'skipped';
                }

                $exhausted = ($recurrence->until !== null && $date > $recurrence->until)
                    || ($recurrence->count !== null && $cycle > $recurrence->count);
                if ($exhausted) {
                    TaskRecurrenceOccurrence::create([
                        'board_id' => $fieldsSource->board_id,
                        'task_id' => $seriesRootId,
                        'occurrence_date' => $date,
                        'created_task_id' => null,
                        'created_at' => now(),
                    ]);
                    $this->events->emit('task.recurrence_completed', $fieldsSource, [
                        'organization_id' => $fieldsSource->organization_id,
                        'board_id' => $fieldsSource->board_id,
                        'task_id' => $seriesRootId,
                    ]);

                    return 'terminal';
                }

                $instance = $this->buildInstance($fieldsSource, $recurrence, $date);
                TaskRecurrenceOccurrence::create([
                    'board_id' => $fieldsSource->board_id,
                    'task_id' => $seriesRootId,
                    'occurrence_date' => $date,
                    'created_task_id' => $instance->id,
                    'created_at' => now(),
                ]);

                return 'created';
            });
        } catch (UniqueConstraintViolationException) {
            // Another run (retried or overlapping) won the race for this
            // occurrence date first. Its transaction is what stands; this
            // one rolled back in full, including any task it had built.
            return 'skipped';
        }
    }

    private function seriesEnded(string $seriesRootId): bool
    {
        return TaskRecurrenceOccurrence::query()
            ->where('task_id', $seriesRootId)->whereNull('created_task_id')->exists();
    }

    private function buildInstance(Task $root, TaskRecurrence $recurrence, string $occurrenceDate): Task
    {
        $root->loadMissing(['collaborators', 'columnValues', 'columnPeople', 'columnLabels']);
        $board = $root->board;
        $actorId = $root->updated_by;

        $status = TaskStatusOption::query()->where('board_id', $board->id)->active()
            ->whereNotIn('category', [StatusCategory::Completed, StatusCategory::Cancelled])
            ->orderBy('position')->firstOrFail();

        $instance = new Task;
        $instance->forceFill([
            'organization_id' => $root->organization_id,
            'board_id' => $board->id,
            'group_id' => $root->group_id,
            'number' => $this->numbers->next(RecordNumberGenerator::TASKS, $this->numberingPlace($board)),
            'title' => $root->title,
            'description' => $root->description,
            'description_plain' => $root->description_plain,
            'owner_user_id' => $root->owner_user_id,
            'status_option_id' => $status->id,
            'priority_option_id' => $root->priority_option_id,
            'due_on' => $occurrenceDate,
            'due_revision' => 1,
            'position' => $this->nextPosition($board, $root->group_id),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            // `after_completion` ONLY (fix round 1, task-6 — CRITICAL: the
            // series otherwise dies after one instance, because
            // TaskWriter's completion hook only relays for a task whose OWN
            // `recurrence` is set, and this instance is what the NEXT
            // completion acts on). Never for `on_schedule`: that mode's
            // hourly sweep already re-scans the ORIGINAL root by id forever
            // — copying recurrence onto every generated instance too would
            // make each one its own new series root on the next sweep,
            // fanning out a second, uncoordinated schedule from every
            // instance's own due_on baseline.
            'recurrence' => $recurrence->mode === 'after_completion' ? $root->recurrence : null,
        ])->save();

        foreach ($root->collaborators as $collaborator) {
            TaskCollaborator::query()->firstOrCreate([
                'task_id' => $instance->id,
                'user_id' => $collaborator->user_id,
            ], [
                'board_id' => $board->id,
                'added_by' => $actorId,
            ]);
        }
        foreach ($root->columnValues as $value) {
            (new TaskColumnValue)->forceFill([
                'board_id' => $board->id,
                'task_id' => $instance->id,
                'column_definition_id' => $value->column_definition_id,
                ...$value->only(['text_value', 'number_value', 'boolean_value', 'date_value', 'url_value', 'link_label_value']),
            ])->save();
        }
        foreach ($root->columnPeople as $person) {
            (new TaskColumnPerson)->forceFill([
                'board_id' => $board->id,
                'task_id' => $instance->id,
                'column_definition_id' => $person->column_definition_id,
                'user_id' => $person->user_id,
            ])->save();
        }
        foreach ($root->columnLabels as $label) {
            (new TaskColumnLabel)->forceFill([
                'board_id' => $board->id,
                'task_id' => $instance->id,
                'column_definition_id' => $label->column_definition_id,
                'column_option_id' => $label->column_option_id,
            ])->save();
        }
        // Deliberately NOT copied: updates, files, activity, completion
        // state, dependencies (task-6 brief). A dependency copied forward
        // would point the new instance at a blocker that is, by the time it
        // exists, already finished.

        $scope = TaskPlace::auditScope($board);
        $audit = $this->audit->log('task.recurrence_created', target: $instance, changes: [
            'before' => [],
            'after' => ['task_id' => $instance->id, 'source_task_id' => $root->id, 'occurrence_date' => $occurrenceDate],
        ], metadata: [
            'board_id' => $board->id,
            'task_id' => $instance->id,
            'source_task_id' => $root->id,
            'occurrence_date' => $occurrenceDate,
        ], scopeType: $scope['type'], scopeId: $scope['id']);
        TaskActivity::create([
            'board_id' => $board->id,
            'task_id' => $instance->id,
            'actor_user_id' => $actorId,
            'kind' => 'task.recurrence_created',
            'summary' => 'Task created by recurrence',
            'data' => [
                'source_task_id' => $root->id,
                'occurrence_date' => $occurrenceDate,
                'audit_event_id' => $audit?->id,
            ],
        ]);
        $this->events->emit('task.created', $instance, [
            'organization_id' => $instance->organization_id,
            'board_id' => $instance->board_id,
            'task_id' => $instance->id,
            'company_id' => $board->company_id,
            'franchise_id' => $board->franchise_id,
            'owner_user_id' => $instance->owner_user_id,
            'status_option_id' => $instance->status_option_id,
            'due_on' => $instance->due_on?->format('Y-m-d'),
            'due_revision' => (int) $instance->due_revision,
            'source_task_id' => $root->id,
        ]);

        return $instance;
    }

    private function nextPosition(TaskBoard $board, ?string $groupId): int
    {
        return (int) Task::query()->where('board_id', $board->id)->active()
            ->where('group_id', $groupId)->whereNull('parent_task_id')
            ->lockForUpdate()->max('position') + 1;
    }

    private function numberingPlace(TaskBoard $board): mixed
    {
        return $board->franchise()->first()
            ?? $board->company()->first()
            ?? $board->organization()->firstOrFail();
    }

    private function step(string $date, TaskRecurrence $recurrence, int $steps): string
    {
        $amount = $recurrence->every * $steps;
        $carbon = Carbon::parse($date);
        $advanced = match ($recurrence->unit) {
            'day' => $carbon->addDays($amount),
            'week' => $carbon->addWeeks($amount),
            'month' => $carbon->addMonths($amount),
        };

        return $advanced->toDateString();
    }

    private function timezone(Task $task): string
    {
        $scope = $task->board->franchise ?? $task->board->company ?? $task->board->organization;

        return (string) $this->settings->get('timezone', $scope);
    }
}
