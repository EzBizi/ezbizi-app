<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one write path for tasks: the controller and (later) the automation
 * runner both call this, so a rule can do nothing a person could not and both
 * raise the same events. Permission is the CALLER's job (policy / runner).
 *
 * Payloads carry ids and the task's own fields only — never a phone or email.
 */
class TaskWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /** @param array{title: string, group_id?: ?string, status?: ?string, assignee_user_id?: ?string, due_on?: ?string, about_type?: ?string, about_id?: ?string, priority?: ?string, estimate_minutes?: ?int} $input */
    public function create(TaskBoard $board, array $input, ?User $actor, ?string $automationId = null): Task
    {
        return DB::transaction(function () use ($board, $input, $actor, $automationId) {
            $groupId = $input['group_id'] ?? null;
            $groupId = $groupId !== null
                ? $this->groupOn($board, $groupId)->id
                : $board->groups()->value('id');
            $status = $input['status'] ?? Task::STATUS_NOT_STARTED;

            $task = Task::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'group_id' => $groupId,
                'company_id' => TaskBoards::companyIdOf($board),
                'franchise_id' => $board->franchise_id,
                'title' => $input['title'],
                'status' => $status,
                'assignee_user_id' => $input['assignee_user_id'] ?? null,
                'due_on' => $input['due_on'] ?? null,
                'completed_at' => $status === Task::STATUS_DONE ? now() : null,
                'about_type' => $input['about_type'] ?? null,
                'about_id' => $input['about_id'] ?? null,
                'priority' => $input['priority'] ?? null,
                'estimate_minutes' => $input['estimate_minutes'] ?? null,
                'created_by_user_id' => $actor?->getKey(),
                'created_by_automation_id' => $automationId,
            ]);

            $this->audit->log('task.created', $task, actor: $actor);
            $this->events->emitAfterCommit('task.created', $task, [
                'assignee_user_id' => $task->assignee_user_id,
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
                'title', 'group_id', 'status', 'assignee_user_id', 'due_on', 'about_type',
                'about_id', 'priority', 'estimate_minutes',
            ]));

            if (isset($fields['group_id'])) {
                $this->groupOn($task->board, $fields['group_id']);
            }

            $before = $task->status;
            $task->fill($fields);

            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === Task::STATUS_DONE ? now() : null;
            }
            if ($task->isDirty('due_on')) {
                // A new due date is a new chance to be overdue.
                $task->overdue_announced_at = null;
            }

            if (! $task->isDirty()) {
                return $task;
            }

            $statusChanged = $task->isDirty('status');
            $this->audit->logChange('task.updated', $task, $actor);
            $task->save();

            if ($statusChanged) {
                $this->events->emitAfterCommit('task.status_changed', $task, [
                    'assignee_user_id' => $task->assignee_user_id,
                    'before' => ['status' => $before],
                    'after' => ['status' => $task->status] + $this->snapshot($task),
                ]);
            }

            return $task;
        });
    }

    public function delete(Task $task, ?User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
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

    private function groupOn(TaskBoard $board, string $groupId): TaskGroup
    {
        return TaskGroup::query()->where('board_id', $board->id)->find($groupId)
            ?? throw ValidationException::withMessages(['group_id' => 'That group is not on this board.']);
    }

    private function snapshot(Task $task): array
    {
        return [
            'board_id' => $task->board_id,
            'title' => $task->title,
            'status' => $task->status,
            'assignee_user_id' => $task->assignee_user_id,
            'due_on' => $task->due_on?->toDateString(),
            'about_type' => $task->about_type,
            'about_id' => $task->about_id,
        ];
    }
}
