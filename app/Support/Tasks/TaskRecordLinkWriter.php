<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskRecordLink;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskRecordLinkWriter
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskRecordLinkRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    public function create(
        User $actor,
        Task $task,
        ViewScope $scope,
        int $expectedVersion,
        string $type,
        string $recordId,
    ): TaskRecordLink {
        return DB::transaction(function () use ($actor, $task, $scope, $expectedVersion, $type, $recordId): TaskRecordLink {
            [$board, $locked] = $this->lock($actor, $task, $scope, $expectedVersion);
            $definition = $this->registry->get($type);
            $record = $definition->resolve($actor, $board, $scope, $recordId);
            if ($record === null) {
                throw ValidationException::withMessages(['record_id' => 'That record is unavailable in this board and view.']);
            }
            $existing = TaskRecordLink::query()->where('task_id', $locked->id)
                ->where('record_type', $type)->where('record_id', $recordId)->first();
            if ($existing !== null) {
                return $existing;
            }
            $presented = $definition->present($record);
            $link = TaskRecordLink::create([
                'board_id' => $board->id, 'task_id' => $locked->id,
                'record_type' => $type, 'record_id' => $recordId,
                'display_snapshot' => ['label' => $presented['label'], 'subtitle' => $presented['subtitle']],
                'created_by' => $actor->id,
            ]);
            $this->changed($locked, $board, $actor, 'task.record_link_added', $link, [
                'record_type' => $type, 'link_id' => $link->id,
            ]);

            return $link;
        });
    }

    public function remove(
        User $actor,
        Task $task,
        TaskRecordLink $link,
        ViewScope $scope,
        int $expectedVersion,
    ): void {
        DB::transaction(function () use ($actor, $task, $link, $scope, $expectedVersion): void {
            [$board, $locked] = $this->lock($actor, $task, $scope, $expectedVersion);
            $lockedLink = TaskRecordLink::query()->where('task_id', $locked->id)->lockForUpdate()->findOrFail($link->id);
            $data = ['record_type' => $lockedLink->record_type, 'link_id' => $lockedLink->id];
            $lockedLink->delete();
            $this->changed($locked, $board, $actor, 'task.record_link_removed', $lockedLink, $data);
        });
    }

    /** @return array{TaskBoard,Task} */
    private function lock(User $actor, Task $task, ViewScope $scope, int $expectedVersion): array
    {
        $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($task->board_id);
        if ($board->visibility === BoardVisibility::Private) {
            TaskBoardMember::query()->where('board_id', $board->id)->where('user_id', $actor->id)->lockForUpdate()->first();
        }
        $this->access->viewDecision($actor, $board, $scope)->authorize();
        if (! $actor->hasPermission('tasks.edit')) {
            Refusal::permission('change task record links')->authorize();
        }
        if ($actor->organization?->isReadOnly()) {
            throw ValidationException::withMessages([
                'organization' => 'This organization is read-only because its subscription has ended.',
            ]);
        }
        $locked = Task::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($task->id);
        if ((int) $locked->version !== $expectedVersion) {
            throw new TaskRecordLinkConflict((int) $locked->version);
        }

        return [$board, $locked];
    }

    private function changed(Task $task, TaskBoard $board, User $actor, string $action, TaskRecordLink $link, array $data): void
    {
        $task->forceFill([
            'version' => (int) $task->version + 1,
            'detail_version' => (int) $task->detail_version + 1,
            'updated_by' => $actor->id,
        ])->save();
        $place = TaskPlace::auditScope($board);
        $this->audit->log($action, target: $link, changes: ['after' => $data],
            metadata: ['board_id' => $board->id, 'task_id' => $task->id], actor: $actor,
            scopeType: $place['type'], scopeId: $place['id']);
        TaskActivity::create([
            'board_id' => $board->id, 'task_id' => $task->id, 'actor_user_id' => $actor->id,
            'kind' => $action, 'summary' => $action === 'task.record_link_added' ? 'Record linked' : 'Record unlinked',
            'data' => $data,
        ]);
    }
}
