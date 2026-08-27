<?php

namespace App\Support\Tasks;

use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class TaskNotificationPresenter
{
    public function __construct(private readonly TaskBoardAccess $access) {}

    public function constrain(Builder $query, User $user): Builder
    {
        $visibleBoardIds = $this->access
            ->constrainBackgroundVisible(TaskBoard::query()->active(), $user)
            ->select('task_boards.id');

        return $query->where(function (Builder $visible) use ($user, $visibleBoardIds): void {
            $visible->where('notifications.source', '<>', 'task')
                ->orWhere(function (Builder $taskNotifications) use ($user, $visibleBoardIds): void {
                    $taskNotifications->where('notifications.source', 'task')
                        ->where('notifications.subject_type', Task::class)
                        ->whereExists(function (QueryBuilder $intents) use ($user, $visibleBoardIds): void {
                            $intents->selectRaw('1')->from('task_notification_intents as visible_intents')
                                ->join('tasks as visible_tasks', function ($join): void {
                                    $join->on('visible_tasks.organization_id', '=', 'visible_intents.organization_id')
                                        ->on('visible_tasks.board_id', '=', 'visible_intents.board_id')
                                        ->on('visible_tasks.id', '=', 'visible_intents.task_id');
                                })
                                ->whereColumn('visible_intents.id', 'notifications.delivery_key')
                                ->whereColumn('visible_intents.organization_id', 'notifications.organization_id')
                                ->whereColumn('visible_intents.task_id', 'notifications.subject_id')
                                ->where('visible_intents.recipient_user_id', $user->id)
                                ->whereNull('visible_tasks.archived_at')
                                ->whereIn('visible_tasks.board_id', $visibleBoardIds)
                                ->where(fn (QueryBuilder $relevant) => $this->relevance($relevant));
                        });
                });
        });
    }

    public function visible(Notification $notification, User $user): bool
    {
        return $this->constrain(Notification::query()->whereKey($notification->id), $user)->exists();
    }

    public function present(Notification $notification): array
    {
        $route = null;
        if ($notification->source === 'task' && $notification->subject_type === Task::class) {
            $task = Task::query()->active()->find($notification->subject_id);
            if ($task !== null) {
                $route = "/tasks/boards/{$task->board_id}/tasks/{$task->id}";
            }
        }

        return [
            'id' => $notification->id,
            'source' => $notification->source,
            'title' => $notification->title,
            'body' => $notification->body,
            'subject' => [
                'type' => $notification->subject_type ? class_basename($notification->subject_type) : null,
                'id' => $notification->subject_id,
            ],
            'meta' => ['route' => $route],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    private function relevance(QueryBuilder $query): void
    {
        $query->where(function (QueryBuilder $owner): void {
            $owner->whereIn('visible_intents.kind', ['task.assigned', 'task.overdue', 'task.due_reminder'])
                ->whereColumn('visible_tasks.owner_user_id', 'visible_intents.recipient_user_id');
        })->orWhere(function (QueryBuilder $collaborator): void {
            $collaborator->where('visible_intents.kind', 'task.collaborator_added')
                ->whereExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')->from('task_collaborators as visible_collaborators')
                    ->whereColumn('visible_collaborators.task_id', 'visible_tasks.id')
                    ->whereColumn('visible_collaborators.user_id', 'visible_intents.recipient_user_id'));
        })->orWhere(function (QueryBuilder $mentioned): void {
            $mentioned->where('visible_intents.kind', 'task.mentioned')
                ->where(fn (QueryBuilder $mentions) => $this->activeMention($mentions));
        })->orWhere(function (QueryBuilder $changed): void {
            $changed->where('visible_intents.kind', 'task.changed')
                ->where(function (QueryBuilder $watching): void {
                    $watching->whereColumn('visible_tasks.owner_user_id', 'visible_intents.recipient_user_id')
                        ->orWhereExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')->from('task_collaborators as watching_collaborators')
                            ->whereColumn('watching_collaborators.task_id', 'visible_tasks.id')
                            ->whereColumn('watching_collaborators.user_id', 'visible_intents.recipient_user_id'))
                        ->orWhereExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')->from('task_updates as watching_updates')
                            ->whereColumn('watching_updates.task_id', 'visible_tasks.id')
                            ->whereColumn('watching_updates.author_user_id', 'visible_intents.recipient_user_id')
                            ->whereNull('watching_updates.archived_at'))
                        ->orWhere(fn (QueryBuilder $mentions) => $this->activeMention($mentions))
                        ->orWhereExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')->from('task_watch_preferences as watching_preferences')
                            ->whereColumn('watching_preferences.task_id', 'visible_tasks.id')
                            ->whereColumn('watching_preferences.user_id', 'visible_intents.recipient_user_id')
                            ->where('watching_preferences.preference', 'watched'));
                })->where(function (QueryBuilder $notMuted): void {
                    $notMuted->whereColumn('visible_tasks.owner_user_id', 'visible_intents.recipient_user_id')
                        ->orWhereNotExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')->from('task_watch_preferences as muted_preferences')
                            ->whereColumn('muted_preferences.task_id', 'visible_tasks.id')
                            ->whereColumn('muted_preferences.user_id', 'visible_intents.recipient_user_id')
                            ->where('muted_preferences.preference', 'muted'));
                });
        });
    }

    private function activeMention(QueryBuilder $query): void
    {
        $query->whereExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')
            ->from('task_update_mentions as visible_mentions')
            ->join('task_updates as mentioned_updates', 'mentioned_updates.id', '=', 'visible_mentions.update_id')
            ->whereColumn('visible_mentions.task_id', 'visible_tasks.id')
            ->whereColumn('visible_mentions.user_id', 'visible_intents.recipient_user_id')
            ->whereNull('mentioned_updates.archived_at'));
    }
}
