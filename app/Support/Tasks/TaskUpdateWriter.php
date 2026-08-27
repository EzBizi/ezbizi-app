<?php

namespace App\Support\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskUpdateWriter
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskRichText $richText,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    public function create(
        User $actor,
        Task $task,
        ViewScope $scope,
        string $body,
        string $idempotencyKey,
        ?AutomationEvent $causedBy = null,
    ): TaskUpdate {
        return DB::transaction(function () use ($actor, $task, $scope, $body, $idempotencyKey, $causedBy): TaskUpdate {
            $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($task->board_id);
            if ($board->visibility === BoardVisibility::Private) {
                TaskBoardMember::query()->where('board_id', $board->id)->where('user_id', $actor->id)
                    ->lockForUpdate()->first();
            }
            $this->access->viewDecision($actor, $board, $scope)->authorize();
            if (! $actor->hasPermission('tasks.comment')) {
                Refusal::permission('comment on this task')->authorize();
            }
            $locked = Task::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($task->id);
            $existing = TaskUpdate::query()->where('task_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $content = $this->richText->normalize($body);
            if (trim($content['plain'] ?? '') === '') {
                throw ValidationException::withMessages(['body' => 'Write an update before adding it.']);
            }
            $update = TaskUpdate::create([
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'author_user_id' => $actor->id,
                'body' => $content['html'],
                'body_plain' => $content['plain'],
                'idempotency_key' => $idempotencyKey,
            ]);
            $locked->forceFill([
                'detail_version' => (int) $locked->detail_version + 1,
                'updated_by' => $actor->id,
            ])->save();
            $place = TaskPlace::auditScope($board);
            $this->audit->log('task.update_added', target: $update, changes: [
                'before' => [],
                'after' => ['task_id' => $locked->id, 'update_id' => $update->id, 'body' => $content['html']],
            ], metadata: ['board_id' => $board->id, 'task_id' => $locked->id], actor: $actor,
                causationId: $causedBy?->id, scopeType: $place['type'], scopeId: $place['id']);
            TaskActivity::create([
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'kind' => 'task.update_added',
                'summary' => 'Update added',
                'data' => ['update_id' => $update->id],
            ]);
            $this->events->emit('task.update_added', $locked, [
                'organization_id' => $locked->organization_id,
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'company_id' => $board->company_id,
                'franchise_id' => $board->franchise_id,
                'update_id' => $update->id,
                'author_user_id' => $actor->id,
            ], $actor, $causedBy);

            return $update->fresh(['author']);
        });
    }
}
