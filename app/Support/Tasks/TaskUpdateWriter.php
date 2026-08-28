<?php

namespace App\Support\Tasks;

use App\Models\Attachment;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskNotificationIntent;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Models\TaskUpdateMention;
use App\Models\TaskUpdateVersion;
use App\Models\TaskWatchPreference;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TaskUpdateWriter
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly TaskRichText $richText,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /** @param list<string> $mentionUserIds @param list<string> $attachmentCandidateIds */
    public function create(
        User $actor,
        Task $task,
        ViewScope $scope,
        ?string $body,
        string $idempotencyKey,
        ?AutomationEvent $causedBy = null,
        array $mentionUserIds = [],
        array $attachmentCandidateIds = [],
    ): TaskUpdate {
        return DB::transaction(function () use ($actor, $task, $scope, $body, $idempotencyKey, $causedBy, $mentionUserIds, $attachmentCandidateIds): TaskUpdate {
            [$board, $locked] = $this->lockForComment($actor, $task, $scope);
            $existing = TaskUpdate::query()->where('task_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->load($existing);
            }

            $candidates = $this->claimableCandidates($actor, $locked, $attachmentCandidateIds);
            $content = $this->content($body, $candidates->isNotEmpty());
            $mentions = $this->eligibleMentions($board, $mentionUserIds);
            $update = TaskUpdate::create([
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'author_user_id' => $actor->id,
                'body' => $content['html'],
                'body_plain' => $content['plain'],
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->replaceMentions($update, $mentions);
            $this->claimAttachments($update, $candidates);
            $this->touchTask($locked, $actor);
            $occurrence = $this->record($update, $locked, $board, $actor, 'task.update_added', [], [
                'body' => $content['html'], 'mention_user_ids' => $mentions->modelKeys(),
            ], $causedBy);
            $this->notifyMentions($locked, $actor, $mentions->modelKeys(), $occurrence);
            $this->events->emit('task.update_added', $locked, $this->eventPayload($locked, $board, $update), $actor, $causedBy);

            return $this->load($update);
        });
    }

    /** @param list<string> $mentionUserIds */
    public function update(
        User $actor,
        Task $task,
        TaskUpdate $update,
        ViewScope $scope,
        int $expectedVersion,
        string $body,
        array $mentionUserIds = [],
    ): TaskUpdate {
        return DB::transaction(function () use ($actor, $task, $update, $scope, $expectedVersion, $body, $mentionUserIds): TaskUpdate {
            [$board, $lockedTask] = $this->lockForComment($actor, $task, $scope);
            $locked = TaskUpdate::query()->where('task_id', $lockedTask->id)->lockForUpdate()->findOrFail($update->id);
            $this->authorDecision($actor, $locked);
            $this->assertVersion($locked, $expectedVersion);
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['update' => 'Archived updates cannot be edited.']);
            }

            $content = $this->content($body);
            $mentions = $this->eligibleMentions($board, $mentionUserIds);
            $oldMentionIds = $locked->mentions()->pluck('user_id')->all();
            $before = ['body' => $locked->body, 'mention_user_ids' => $oldMentionIds, 'version' => (int) $locked->version];
            $this->snapshot($locked, $actor);
            $locked->forceFill([
                'body' => $content['html'], 'body_plain' => $content['plain'],
                'version' => (int) $locked->version + 1,
            ])->save();
            $this->replaceMentions($locked, $mentions);
            $this->touchTask($lockedTask, $actor);
            $after = ['body' => $content['html'], 'mention_user_ids' => $mentions->modelKeys(), 'version' => (int) $locked->version];
            $occurrence = $this->record($locked, $lockedTask, $board, $actor, 'task.update_edited', $before, $after);
            $this->notifyMentions($lockedTask, $actor, array_values(array_diff($mentions->modelKeys(), $oldMentionIds)), $occurrence);

            return $this->load($locked);
        });
    }

    public function archive(User $actor, Task $task, TaskUpdate $update, ViewScope $scope, int $expectedVersion): TaskUpdate
    {
        return DB::transaction(function () use ($actor, $task, $update, $scope, $expectedVersion): TaskUpdate {
            [$board, $lockedTask] = $this->lockForComment($actor, $task, $scope);
            $locked = TaskUpdate::query()->where('task_id', $lockedTask->id)->lockForUpdate()->findOrFail($update->id);
            $this->authorDecision($actor, $locked);
            $this->assertVersion($locked, $expectedVersion);
            if ($locked->archived_at === null) {
                $this->snapshot($locked, $actor);
                $locked->forceFill([
                    'version' => (int) $locked->version + 1,
                    'archived_at' => now(), 'archived_by' => $actor->id,
                ])->save();
                $this->touchTask($lockedTask, $actor);
                $this->record($locked, $lockedTask, $board, $actor, 'task.update_archived',
                    ['archived_at' => null, 'version' => $expectedVersion],
                    ['archived_at' => $locked->archived_at->toIso8601String(), 'version' => (int) $locked->version]);
            }

            return $this->load($locked);
        });
    }

    public function setWatchPreference(User $actor, Task $task, ViewScope $scope, ?string $preference): ?TaskWatchPreference
    {
        return DB::transaction(function () use ($actor, $task, $scope, $preference): ?TaskWatchPreference {
            $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($task->board_id);
            $this->lockPrivateMembership($actor, $board);
            $this->access->viewDecision($actor, $board, $scope)->authorize();
            $locked = Task::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($task->id);
            if ($preference === 'muted' && $locked->owner_user_id === $actor->id) {
                throw ValidationException::withMessages(['preference' => 'The task owner is always watching this task and cannot mute it.']);
            }
            if ($preference === 'watched') {
                $this->participants->forTaskParticipation($actor, $board)->authorize();
            }

            $existing = TaskWatchPreference::query()->where('task_id', $locked->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            $before = $existing?->preference;
            if ($preference === null) {
                $existing?->delete();
                $result = null;
            } else {
                $result = TaskWatchPreference::query()->updateOrCreate(
                    ['task_id' => $locked->id, 'user_id' => $actor->id],
                    ['board_id' => $board->id, 'preference' => $preference],
                );
            }
            if ($before !== $preference) {
                $this->touchTask($locked, $actor);
                $this->record($result ?? $locked, $locked, $board, $actor, 'task.watch_preference_changed',
                    ['preference' => $before], ['preference' => $preference]);
            }

            return $result;
        });
    }

    /** @return array{TaskBoard,Task} */
    private function lockForComment(User $actor, Task $task, ViewScope $scope): array
    {
        $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($task->board_id);
        $this->lockPrivateMembership($actor, $board);
        $this->access->viewDecision($actor, $board, $scope)->authorize();
        if (! $actor->hasPermission('tasks.comment')) {
            Refusal::permission('comment on this task')->authorize();
        }

        return [$board, Task::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($task->id)];
    }

    private function lockPrivateMembership(User $actor, TaskBoard $board): void
    {
        if ($board->visibility === BoardVisibility::Private) {
            TaskBoardMember::query()->where('board_id', $board->id)->where('user_id', $actor->id)
                ->lockForUpdate()->first();
        }
    }

    private function authorDecision(User $actor, TaskUpdate $update): void
    {
        if ($update->author_user_id !== $actor->id) {
            Refusal::permission('edit another person\'s task update')->authorize();
        }
    }

    private function assertVersion(TaskUpdate $update, int $expected): void
    {
        if ((int) $update->version !== $expected) {
            throw new TaskUpdateConflict((int) $update->version);
        }
    }

    /** @return array{html:string,plain:string} */
    private function content(?string $body, bool $hasAttachments = false): array
    {
        if (($body === null || trim($body) === '') && $hasAttachments) {
            return ['html' => '', 'plain' => ''];
        }

        $content = $this->richText->normalize($body ?? '');
        if (trim($content['plain'] ?? '') === '') {
            throw ValidationException::withMessages(['body' => 'Write an update before saving it.']);
        }

        return $content;
    }

    /** @param list<string> $ids @return Collection<int,TaskUpdateAttachmentCandidate> */
    private function claimableCandidates(User $actor, Task $task, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            return collect();
        }

        $candidates = TaskUpdateAttachmentCandidate::query()
            ->whereIn('attachment_id', $ids)
            ->orderBy('attachment_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('attachment_id');
        if ($candidates->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'attachment_candidate_ids' => 'One or more staged attachments are unavailable.',
            ]);
        }

        $attachments = Attachment::query()->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($ids as $id) {
            $candidate = $candidates->get($id);
            $attachment = $attachments->get($id);
            $valid = $candidate !== null && $attachment !== null
                && $candidate->organization_id === $task->organization_id
                && $candidate->board_id === $task->board_id
                && $candidate->task_id === $task->id
                && $candidate->actor_user_id === $actor->id
                && $candidate->expires_at->isFuture()
                && $attachment->organization_id === $task->organization_id
                && $attachment->attachable_type === 'tasks'
                && $attachment->attachable_id === $task->id
                && $attachment->uploaded_by_user_id === $actor->id;
            if (! $valid) {
                throw ValidationException::withMessages([
                    'attachment_candidate_ids' => 'One or more staged attachments are unavailable.',
                ]);
            }
            $candidate->setRelation('attachment', $attachment);
        }

        return collect($ids)->map(fn (string $id) => $candidates->get($id));
    }

    /** @param Collection<int,TaskUpdateAttachmentCandidate> $candidates */
    private function claimAttachments(TaskUpdate $update, Collection $candidates): void
    {
        foreach ($candidates as $candidate) {
            $candidate->attachment->forceFill([
                'attachable_type' => 'task_updates',
                'attachable_id' => $update->id,
            ])->save();
            $candidate->delete();
        }
    }

    /** @param list<string> $ids @return Collection<int,User> */
    private function eligibleMentions(TaskBoard $board, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($users->count() !== count($ids)) {
            throw ValidationException::withMessages(['mention_user_ids' => 'One or more mentioned people are unavailable.']);
        }
        foreach ($ids as $id) {
            $this->participants->forTaskParticipation($users->get($id), $board)->authorize();
        }

        return $users->values();
    }

    private function replaceMentions(TaskUpdate $update, Collection $users): void
    {
        $update->mentions()->delete();
        foreach ($users as $user) {
            TaskUpdateMention::create([
                'board_id' => $update->board_id, 'task_id' => $update->task_id,
                'update_id' => $update->id, 'user_id' => $user->id,
            ]);
        }
    }

    private function snapshot(TaskUpdate $update, User $editor): void
    {
        $snapshot = new TaskUpdateVersion;
        $snapshot->forceFill([
            'organization_id' => $update->organization_id, 'board_id' => $update->board_id,
            'task_id' => $update->task_id, 'update_id' => $update->id,
            'version' => (int) $update->version, 'body' => $update->body,
            'body_plain' => $update->body_plain, 'edited_by' => $editor->id, 'created_at' => now(),
        ])->save();
    }

    private function touchTask(Task $task, User $actor): void
    {
        $task->forceFill(['detail_version' => (int) $task->detail_version + 1, 'updated_by' => $actor->id])->save();
    }

    private function record(
        TaskUpdate|TaskWatchPreference|Task $target,
        Task $task,
        TaskBoard $board,
        User $actor,
        string $action,
        array $before,
        array $after,
        ?AutomationEvent $causedBy = null,
    ): string {
        $place = TaskPlace::auditScope($board);
        $audit = $this->audit->log($action, target: $target, changes: ['before' => $before, 'after' => $after],
            metadata: ['board_id' => $board->id, 'task_id' => $task->id], actor: $actor,
            causationId: $causedBy?->id, scopeType: $place['type'], scopeId: $place['id']);
        TaskActivity::create([
            'board_id' => $board->id, 'task_id' => $task->id, 'actor_user_id' => $actor->id,
            'kind' => $action,
            'summary' => match ($action) {
                'task.update_added' => 'Update added',
                'task.update_edited' => 'Update edited',
                'task.update_archived' => 'Update archived',
                default => 'Watch preference changed',
            },
            'data' => ['update_id' => $target instanceof TaskUpdate ? $target->id : null],
        ]);

        return $audit?->id ?? (string) Str::uuid7();
    }

    /** @param list<string> $userIds */
    private function notifyMentions(Task $task, User $actor, array $userIds, string $occurrence): void
    {
        foreach ($userIds as $userId) {
            if ($userId === $actor->id) {
                continue;
            }
            $muted = TaskWatchPreference::query()->where('task_id', $task->id)
                ->where('user_id', $userId)->where('preference', 'muted')->exists();
            if ($muted && $task->owner_user_id !== $userId) {
                continue;
            }
            TaskNotificationIntent::query()->firstOrCreate([
                'recipient_user_id' => $userId, 'kind' => 'task.mentioned', 'source_occurrence_key' => $occurrence,
            ], ['board_id' => $task->board_id, 'task_id' => $task->id, 'available_at' => now()]);
        }
    }

    private function eventPayload(Task $task, TaskBoard $board, TaskUpdate $update): array
    {
        return [
            'organization_id' => $task->organization_id, 'board_id' => $board->id,
            'task_id' => $task->id, 'company_id' => $board->company_id,
            'franchise_id' => $board->franchise_id, 'update_id' => $update->id,
            'author_user_id' => $update->author_user_id,
        ];
    }

    private function load(TaskUpdate $update): TaskUpdate
    {
        return $update->fresh(['author', 'mentions.user', 'versions.editor', 'attachments.uploadedBy']);
    }
}
