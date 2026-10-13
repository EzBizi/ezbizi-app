<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one write path for tasks: the controller and the automation runner both
 * call this, so a rule can do nothing a person could not and both raise the
 * same events. Permission is the CALLER's job (policy / runner).
 *
 * Payloads carry ids and the task's own fields only — never a phone or email.
 * In payloads `status` is the status NAME (built-in and custom alike), with
 * status_id, status_key (tasks.status) and is_done beside it.
 *
 * tasks.status stays the built-in key; a custom status maps to `done` when it
 * is_done and `not_started` otherwise, so Reports keep reading one column.
 */
class TaskWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * @param  array{title: string, parent_task_id?: ?string, group_id?: ?string, status?: ?string, status_id?: ?string, assignee_user_id?: ?string, due_on?: ?string, about_type?: ?string, about_id?: ?string, priority?: ?string, estimate_minutes?: ?int, values?: array<string, mixed>}  $input
     *
     * `status` is a built-in key or a status name on this board (any case).
     */
    public function create(TaskBoard $board, array $input, ?User $actor, ?string $automationId = null): Task
    {
        return DB::transaction(function () use ($board, $input, $actor, $automationId) {
            $parent = null;
            if (! empty($input['parent_task_id'])) {
                $parent = Task::query()->where('board_id', $board->id)->find($input['parent_task_id'])
                    ?? throw ValidationException::withMessages(['parent_task_id' => 'That task is not on this board.']);
                if ($parent->parent_task_id !== null) {
                    throw ValidationException::withMessages(['parent_task_id' => 'A subtask cannot have subtasks of its own.']);
                }
            }

            $groupId = $input['group_id'] ?? null;
            $groupId = match (true) {
                $parent !== null => $parent->group_id,
                $groupId !== null => $this->groupOn($board, $groupId)->id,
                default => $board->groups()->value('id'),
            };

            $status = $this->resolveStatus($board, $input)
                ?? $board->statuses()->where('key', Task::STATUS_NOT_STARTED)->first()
                ?? $board->statuses()->first();

            $task = Task::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'parent_task_id' => $parent?->id,
                'group_id' => $groupId,
                // A subtask is filed exactly where its parent is.
                'company_id' => $parent ? $parent->company_id : TaskBoards::companyIdOf($board),
                'franchise_id' => $parent ? $parent->franchise_id : $board->franchise_id,
                'title' => $input['title'],
                'status' => $status?->statusKey() ?? Task::STATUS_NOT_STARTED,
                'status_id' => $status?->id,
                'assignee_user_id' => $input['assignee_user_id'] ?? null,
                'due_on' => $input['due_on'] ?? null,
                'completed_at' => ($status?->is_done ?? false) ? now() : null,
                'about_type' => $input['about_type'] ?? null,
                'about_id' => $input['about_id'] ?? null,
                'priority' => $input['priority'] ?? null,
                'estimate_minutes' => $input['estimate_minutes'] ?? null,
                'created_by_user_id' => $actor?->getKey(),
                'created_by_automation_id' => $automationId,
            ]);

            if (! empty($input['values'])) {
                $this->writeValues($task, $input['values'], $actor);
            }

            $this->audit->log('task.created', $task, actor: $actor);
            $this->events->emitAfterCommit('task.created', $task, [
                'assignee_user_id' => $task->assignee_user_id,
                'board_id' => $task->board_id,
                'board_name' => $task->board?->name,
                'after' => $this->snapshot($task),
            ]);

            return $task;
        });
    }

    /** Only the keys present in $input change. */
    public function update(Task $task, array $input, ?User $actor): Task
    {
        return DB::transaction(function () use ($task, $input, $actor) {
            $fields = array_intersect_key($input, array_flip([
                'title', 'group_id', 'assignee_user_id', 'due_on', 'about_type',
                'about_id', 'priority', 'estimate_minutes',
            ]));

            if (isset($fields['group_id'])) {
                if ($task->parent_task_id !== null && $fields['group_id'] !== $task->group_id) {
                    throw ValidationException::withMessages(['group_id' => 'A subtask stays in its parent\'s group.']);
                }
                $this->groupOn($task->board, $fields['group_id']);
            }

            $before = $this->statusOf($task);
            $task->fill($fields);

            $status = $this->resolveStatus($task->board, $input);
            if ($status !== null && $status->id !== $task->status_id) {
                $this->applyStatus($task, $status, $before['is_done']);
            }
            if ($task->isDirty('due_on')) {
                // A new due date is a new chance to be overdue.
                $task->overdue_announced_at = null;
            }

            if (! empty($input['values']) && is_array($input['values'])) {
                $this->writeValues($task, $input['values'], $actor);
            }

            if (! $task->isDirty()) {
                return $task;
            }

            $statusChanged = $task->isDirty('status_id') || $task->isDirty('status');
            $groupChanged = $task->isDirty('group_id');
            $this->audit->logChange('task.updated', $task, $actor);
            $task->save();

            if ($groupChanged) {
                Task::query()->withTrashed()->where('parent_task_id', $task->id)->update(['group_id' => $task->group_id]);
            }

            if ($statusChanged) {
                $this->events->emitAfterCommit('task.status_changed', $task, [
                    'assignee_user_id' => $task->assignee_user_id,
                    'board_id' => $task->board_id,
                    'board_name' => $task->board?->name,
                    'before' => $before,
                    'after' => $this->snapshot($task),
                ]);
            }

            return $task;
        });
    }

    /** Deleting a parent takes its subtasks with it (soft, like the parent). */
    public function delete(Task $task, ?User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
            foreach ($task->subtasks as $child) {
                $this->audit->log('task.deleted', $child, actor: $actor);
                $child->delete();
            }
            $this->audit->log('task.deleted', $task, actor: $actor);
            $task->delete();
        });
    }

    public function comment(Task $task, User $author, string $body): TaskUpdate
    {
        return DB::transaction(function () use ($task, $author, $body) {
            $update = TaskUpdate::query()->create([
                'organization_id' => $task->organization_id,
                'task_id' => $task->id,
                'user_id' => $author->getKey(),
                'body' => $body,
            ]);
            $this->audit->log('task.update_posted', $task, actor: $author);

            return $update;
        });
    }

    /**
     * A status's is_done was edited: every task on it (deleted ones too) gets
     * tasks.status and completed_at re-derived.
     */
    public function rederive(TaskStatus $status): void
    {
        $tasks = Task::query()->withTrashed()->where('status_id', $status->id);
        $tasks->clone()->update(['status' => $status->statusKey()]);
        $status->is_done
            ? $tasks->clone()->whereNull('completed_at')->update(['completed_at' => now()])
            : $tasks->clone()->update(['completed_at' => null]);
    }

    /** Before a status is deleted: its tasks move to $to (live ones through update, so events fire). */
    public function moveStatus(TaskStatus $from, TaskStatus $to, ?User $actor): void
    {
        Task::query()->where('status_id', $from->id)->get()
            ->each(fn (Task $t) => $this->update($t, ['status_id' => $to->id], $actor));
        $wasDone = $from->is_done;
        $trashed = Task::onlyTrashed()->where('status_id', $from->id);
        $trashed->clone()->update(['status_id' => $to->id, 'status' => $to->statusKey()]
            + ($to->is_done === $wasDone ? [] : ['completed_at' => $to->is_done ? now() : null]));
    }

    private function groupOn(TaskBoard $board, string $groupId): TaskGroup
    {
        return TaskGroup::query()->where('board_id', $board->id)->find($groupId)
            ?? throw ValidationException::withMessages(['group_id' => 'That group is not on this board.']);
    }

    /** The status named in $input (status_id, or `status` as a key or a name), or null when neither is given. */
    private function resolveStatus(TaskBoard $board, array $input): ?TaskStatus
    {
        if (! empty($input['status_id'])) {
            return TaskStatus::query()->where('board_id', $board->id)->find($input['status_id'])
                ?? throw ValidationException::withMessages(['status_id' => 'That status is not on this board.']);
        }

        if (! isset($input['status']) || $input['status'] === '') {
            return null;
        }

        $wanted = (string) $input['status'];

        return TaskStatus::query()->where('board_id', $board->id)->where('key', $wanted)->first()
            ?? TaskStatus::query()->where('board_id', $board->id)->get()
                ->first(fn (TaskStatus $s) => mb_strtolower($s->name) === mb_strtolower($wanted))
            ?? throw ValidationException::withMessages(['status' => "This board has no status called {$wanted}."]);
    }

    private function applyStatus(Task $task, TaskStatus $status, bool $wasDone): void
    {
        $task->status_id = $status->id;
        $task->status = $status->statusKey();
        $task->setRelation('statusRow', $status);

        if ($status->is_done && ! $wasDone) {
            $task->completed_at = now();
        } elseif (! $status->is_done) {
            $task->completed_at = null;
        }
    }

    /** @return array{status: string, status_id: ?string, status_name: string, status_key: string, is_done: bool} */
    private function statusOf(Task $task): array
    {
        $row = $task->status_id ? $task->statusRow : null;
        $name = $row?->name ?? (Task::STATUSES[$task->status] ?? (string) $task->status);

        return [
            'status' => $name,
            'status_id' => $task->status_id,
            'status_name' => $name,
            'status_key' => (string) $task->status,
            'is_done' => $row?->is_done ?? $task->status === Task::STATUS_DONE,
        ];
    }

    /**
     * column_id => value; null clears. Each value is checked against its
     * column's type before anything is written.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeValues(Task $task, array $values, ?User $actor): void
    {
        $columns = TaskColumn::query()->where('board_id', $task->board_id)->get()->keyBy('id');
        $assignable = null;
        $rows = [];

        foreach ($values as $columnId => $value) {
            $key = "values.{$columnId}";
            $column = $columns->get($columnId)
                ?? throw ValidationException::withMessages([$key => 'That column is not on this board.']);

            if ($value === null) {
                $rows[$column->id] = null;

                continue;
            }

            $row = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_user_id' => null];
            $ok = true;
            $why = '';

            switch ($column->type) {
                case 'text':
                    $ok = is_string($value) && mb_strlen($value) <= 5000;
                    $why = 'Text, up to 5000 characters.';
                    $row['value_text'] = $value;
                    break;
                case 'number':
                    $ok = (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
                        && abs((float) $value) < 1e11;
                    $why = 'This column takes a number.';
                    $row['value_number'] = $ok ? (float) $value : null;
                    break;
                case 'date':
                    $ok = is_string($value) && \DateTime::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
                    $why = 'A date as YYYY-MM-DD.';
                    $row['value_date'] = $value;
                    break;
                case 'person':
                    $assignable ??= app(TaskBoards::class)->assignable($task->board)->pluck('id')->all();
                    $ok = in_array($value, $assignable, true);
                    $why = 'That person cannot be named on this board.';
                    $row['value_user_id'] = $value;
                    break;
                case 'checkbox':
                    $ok = in_array($value, [true, false, 0, 1, '0', '1'], true);
                    $why = 'This column takes true or false.';
                    $row['value_number'] = $ok && (bool) $value ? 1 : 0;
                    break;
                default: // status, dropdown: the option's id
                    $ok = is_string($value) && in_array($value, array_column($column->options(), 'id'), true);
                    $why = 'That is not one of this column\'s options.';
                    $row['value_text'] = $value;
            }

            if (! $ok) {
                throw ValidationException::withMessages([$key => $why]);
            }
            $rows[$column->id] = $row;
        }

        foreach ($rows as $columnId => $row) {
            $row === null
                ? TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $columnId)->delete()
                : TaskColumnValue::query()->updateOrCreate(
                    ['task_id' => $task->id, 'column_id' => $columnId],
                    ['organization_id' => $task->organization_id] + $row,
                );
        }

        // Ids only: a text value can hold anything somebody typed.
        $this->audit->log('task.values_changed', $task, metadata: ['column_ids' => array_keys($rows)], actor: $actor);
    }

    private function snapshot(Task $task): array
    {
        return [
            'board_id' => $task->board_id,
            'parent_task_id' => $task->parent_task_id,
            'title' => $task->title,
            ...$this->statusOf($task),
            'assignee_user_id' => $task->assignee_user_id,
            'due_on' => $task->due_on?->toDateString(),
            'about_type' => $task->about_type,
            'about_id' => $task->about_id,
        ];
    }
}
