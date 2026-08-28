<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskDependency;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draws or removes a "blocks" edge between two tasks (Tasks Phase 2 design
 * §9, task-4 brief).
 *
 * DISPLAY ONLY. This writer never reads or writes `completed_at`, never
 * refuses a completion, and never touches a due date. It authorizes, checks
 * the edge is well-formed (same board, not a duplicate, not a cycle), and
 * persists — the same shape as TaskRecordLinkWriter for a different relation:
 * authorize, transact, audit, outbox.
 */
final class TaskDependencyWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TaskDependencyCycle $cycle,
    ) {}

    public function add(User $actor, Task $blocked, Task $blocker): TaskDependency
    {
        return DB::transaction(function () use ($actor, $blocked, $blocker): TaskDependency {
            $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($blocked->board_id);
            $this->authorize($actor, $board);

            $lockedBlocked = Task::query()->where('board_id', $board->id)->active()
                ->lockForUpdate()->findOrFail($blocked->id);

            // Checked against the pre-lock board_id before ever scoping a lookup
            // to $board->id — locking the blocker with a board-scoped query first
            // would 404 a cross-board pair instead of naming what went wrong.
            if ($blocker->board_id !== $board->id) {
                Refusal::dependencyCrossBoard()->authorize();
            }

            $lockedBlocker = Task::query()->where('board_id', $board->id)->active()
                ->lockForUpdate()->findOrFail($blocker->id);

            if ($lockedBlocker->id === $lockedBlocked->id) {
                throw ValidationException::withMessages([
                    'blocker_task_id' => 'A task cannot block itself.',
                ]);
            }

            $existing = TaskDependency::query()
                ->where('blocker_task_id', $lockedBlocker->id)
                ->where('blocked_task_id', $lockedBlocked->id)
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'blocker_task_id' => 'This task is already blocked by that task.',
                ]);
            }

            // Run inside this same transaction, after the board lock above, so a
            // concurrent insert on this board cannot slip a cycle past it.
            if ($this->cycle->wouldCreate($board, $lockedBlocker->id, $lockedBlocked->id)) {
                Refusal::dependencyCycle()->authorize();
            }

            $edge = TaskDependency::create([
                'board_id' => $board->id,
                'blocker_task_id' => $lockedBlocker->id,
                'blocked_task_id' => $lockedBlocked->id,
                'created_by' => $actor->id,
            ]);

            $this->touch($lockedBlocked, $actor);
            $this->recorded($actor, $board, $edge, 'task.dependency_added', 'Dependency added');

            return $edge;
        });
    }

    public function remove(User $actor, TaskDependency $edge): void
    {
        DB::transaction(function () use ($actor, $edge): void {
            $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($edge->board_id);
            $this->authorize($actor, $board);

            $locked = TaskDependency::query()->where('board_id', $board->id)
                ->lockForUpdate()->findOrFail($edge->id);
            $blocked = Task::query()->where('board_id', $board->id)
                ->lockForUpdate()->find($locked->blocked_task_id);

            $locked->delete();

            if ($blocked !== null) {
                $this->touch($blocked, $actor);
            }
            $this->recorded($actor, $board, $locked, 'task.dependency_removed', 'Dependency removed');
        });
    }

    private function authorize(User $actor, TaskBoard $board): void
    {
        if (! $actor->hasPermission('tasks.edit')) {
            Refusal::permission('change task dependencies')->authorize();
        }
        if ($actor->organization?->isReadOnly()) {
            throw ValidationException::withMessages([
                'organization' => 'This organization is read-only because its subscription has ended.',
            ]);
        }
    }

    /**
     * `blocked_by` lives on the blocked task's detail response, so a change
     * to its edges is a change to that task's detail content — bumping
     * `detail_version` invalidates its ETag the same way TaskUpdateWriter
     * does for an added update. `version` is left alone: dependencies carry
     * no `expected_task_version` and are not part of the optimistic-lock
     * contract other writers use it for.
     */
    private function touch(Task $task, User $actor): void
    {
        $task->forceFill(['detail_version' => (int) $task->detail_version + 1, 'updated_by' => $actor->id])->save();
    }

    private function recorded(User $actor, TaskBoard $board, TaskDependency $edge, string $action, string $summary): void
    {
        $place = TaskPlace::auditScope($board);
        $data = [
            'dependency_id' => $edge->id,
            'blocker_task_id' => $edge->blocker_task_id,
            'blocked_task_id' => $edge->blocked_task_id,
        ];
        $this->audit->log($action, target: $edge, changes: ['after' => $data],
            metadata: ['board_id' => $board->id], actor: $actor,
            scopeType: $place['type'], scopeId: $place['id']);
        TaskActivity::create([
            'board_id' => $board->id, 'task_id' => $edge->blocked_task_id, 'actor_user_id' => $actor->id,
            'kind' => $action, 'summary' => $summary, 'data' => $data,
        ]);
        $this->events->emit($action, $edge, [
            'organization_id' => $board->organization_id,
            'board_id' => $board->id,
            ...$data,
        ], $actor);
    }
}
