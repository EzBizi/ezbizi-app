<?php

namespace App\Support\Tasks;

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
 * it. TaskWriter calls afterCompletion() synchronously, inside its own
 * completion transaction, the moment a task's status enters the Completed
 * category — see TaskWriter::recordRecurrenceOnCompletion(). That call is
 * wrapped in a try/catch there: a failing rule in this class can never fail
 * the completion that triggered it.
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

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly RecordNumberGenerator $numbers,
    ) {}

    /**
     * The hourly sweep for `on_schedule` series. `after_completion` series
     * are handled entirely by afterCompletion(), called from TaskWriter, and
     * never appear in this scan.
     */
    public function onSchedule(int $limit = 500): int
    {
        $created = 0;
        $query = Task::withoutGlobalScopes()
            ->whereNotNull('recurrence')
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
     * Called from TaskWriter the moment a task's status enters the
     * Completed category (create() and applyUpdate() both call this — a
     * task can be created already-completed). Computes exactly ONE next
     * occurrence, dated `every`/`unit` from the completion that just
     * happened, and records it. Never throws: any failure here is caught by
     * the caller and must never fail the completion (task-6 brief).
     */
    public function afterCompletion(Task $task): void
    {
        $recurrence = TaskRecurrenceCodec::parse($task->recurrence);
        if ($recurrence === null || $recurrence->mode !== 'after_completion') {
            return;
        }
        if ($this->seriesEnded($task)) {
            return;
        }
        $task->loadMissing(['board.company', 'board.franchise', 'board.organization']);
        if ($task->board === null) {
            return;
        }
        $completionDate = ($task->completed_at ?? now())->copy()
            ->setTimezone($this->timezone($task))->toDateString();
        $nextDate = $this->step($completionDate, $recurrence, 1);
        $cycle = (int) TaskRecurrenceOccurrence::query()
            ->where('task_id', $task->id)->whereNotNull('created_task_id')->count() + 1;

        $this->attemptOccurrence($task, $recurrence, $nextDate, $cycle);
    }

    private function advanceSchedule(Task $root, TaskRecurrence $recurrence): int
    {
        if ($this->seriesEnded($root)) {
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
            $result = $this->attemptOccurrence($root, $recurrence, $date, $cycle);
            if ($result === 'terminal') {
                break;
            }
            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /** @return 'created'|'terminal'|'skipped' */
    private function attemptOccurrence(Task $root, TaskRecurrence $recurrence, string $date, int $cycle): string
    {
        try {
            return DB::transaction(function () use ($root, $recurrence, $date, $cycle): string {
                $exists = TaskRecurrenceOccurrence::query()
                    ->where('task_id', $root->id)->where('occurrence_date', $date)->exists();
                if ($exists) {
                    return 'skipped';
                }

                $exhausted = ($recurrence->until !== null && $date > $recurrence->until)
                    || ($recurrence->count !== null && $cycle > $recurrence->count);
                if ($exhausted) {
                    TaskRecurrenceOccurrence::create([
                        'board_id' => $root->board_id,
                        'task_id' => $root->id,
                        'occurrence_date' => $date,
                        'created_task_id' => null,
                        'created_at' => now(),
                    ]);
                    $this->events->emit('task.recurrence_completed', $root, [
                        'organization_id' => $root->organization_id,
                        'board_id' => $root->board_id,
                        'task_id' => $root->id,
                    ]);

                    return 'terminal';
                }

                $instance = $this->buildInstance($root, $date);
                TaskRecurrenceOccurrence::create([
                    'board_id' => $root->board_id,
                    'task_id' => $root->id,
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

    private function seriesEnded(Task $root): bool
    {
        return TaskRecurrenceOccurrence::query()
            ->where('task_id', $root->id)->whereNull('created_task_id')->exists();
    }

    private function buildInstance(Task $root, string $occurrenceDate): Task
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
