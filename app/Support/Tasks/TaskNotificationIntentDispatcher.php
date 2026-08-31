<?php

namespace App\Support\Tasks;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskNotificationIntent;
use App\Models\TaskWatchPreference;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class TaskNotificationIntentDispatcher
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TaskBoardAccess $access,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function dispatchOne(string $intentId): bool
    {
        $claim = DB::transaction(function () use ($intentId): ?array {
            $intent = TaskNotificationIntent::withoutGlobalScopes()->lockForUpdate()->find($intentId);
            if ($intent === null
                || in_array($intent->status, ['delivered', 'suppressed', 'failed'], true)
                || $intent->available_at?->isFuture()
                || ($intent->status === 'processing' && $intent->claimed_until?->isFuture())) {
                return null;
            }

            $token = (string) Str::uuid7();
            TaskNotificationIntent::withoutGlobalScopes()->whereKey($intentId)->update([
                'status' => 'processing', 'claim_token' => $token,
                'claimed_until' => now()->addMinutes(5), 'attempts' => (int) $intent->attempts + 1,
                'last_error_code' => null, 'updated_at' => now(),
            ]);

            return ['organization_id' => $intent->organization_id, 'token' => $token];
        });
        if ($claim === null) {
            return false;
        }

        $organization = Organization::query()->find($claim['organization_id']);
        if ($organization === null) {
            $this->finish($intentId, $claim['token'], 'suppressed');

            return false;
        }

        try {
            return $this->tenant->run($organization, fn (): bool => DB::transaction(function () use ($intentId, $claim): bool {
                $intent = TaskNotificationIntent::query()->with(['task.board', 'recipient'])->find($intentId);
                $task = $intent?->task;
                $recipient = $intent?->recipient;
                if ($intent === null || $task === null || $recipient === null || ! $recipient->is_active
                    || $task->archived_at !== null || $task->board === null
                    || $this->access->backgroundViewDecision($recipient, $task->board)->denied()
                    || ! $this->stillRelevant($intent, $task, $recipient)) {
                    $this->finish($intentId, $claim['token'], 'suppressed');

                    return false;
                }

                [$title, $body] = $this->copy($intent->kind, $task);

                // Null means the organization has task notifications switched
                // off. That is a settled outcome, not a failure to retry: the
                // setting will still be off in ten seconds, and an intent parked
                // in `pending` would deliver the moment somebody switched it back
                // on — telling them about a task assignment from last month.
                $delivered = $this->notifications
                    ->dispatchUnique($recipient, $title, $body, $task, 'task', $intent->id) !== null;

                $this->finish($intentId, $claim['token'], $delivered ? 'delivered' : 'suppressed');

                return $delivered;
            }));
        } catch (Throwable $exception) {
            $current = TaskNotificationIntent::withoutGlobalScopes()->find($intentId);
            $failed = (int) ($current?->attempts ?? 0) >= TaskNotificationIntent::MAX_ATTEMPTS;
            TaskNotificationIntent::withoutGlobalScopes()
                ->whereKey($intentId)->where('claim_token', $claim['token'])->update([
                    'status' => $failed ? 'failed' : 'pending',
                    'claim_token' => null, 'claimed_until' => null,
                    'available_at' => now()->addSeconds(min(3600, 10 * (2 ** max(0, (int) ($current?->attempts ?? 1) - 1)))),
                    'last_error_code' => class_basename($exception), 'updated_at' => now(),
                ]);

            return false;
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $ids = TaskNotificationIntent::withoutGlobalScopes()
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(fn ($expired) => $expired->where('status', 'processing')->where('claimed_until', '<=', now()));
            })
            ->orderBy('available_at')->limit(max(1, min($limit, 100)))->pluck('id');

        return $ids->sum(fn (string $id): int => $this->dispatchOne($id) ? 1 : 0);
    }

    private function stillRelevant(TaskNotificationIntent $intent, Task $task, User $recipient): bool
    {
        return match ($intent->kind) {
            'task.assigned', 'task.overdue', 'task.due_reminder' => $task->owner_user_id === $recipient->id,
            'task.collaborator_added' => $task->collaborators()->where('user_id', $recipient->id)->exists(),
            'task.mentioned' => $task->updates()->active()->whereHas('mentions',
                fn ($mentions) => $mentions->where('user_id', $recipient->id))->exists(),
            'task.changed' => $this->isWatching($task, $recipient),
            default => false,
        };
    }

    private function isWatching(Task $task, User $user): bool
    {
        if ($task->owner_user_id === $user->id) {
            return true;
        }
        $preference = TaskWatchPreference::query()->where('task_id', $task->id)
            ->where('user_id', $user->id)->value('preference');
        if ($preference === 'muted') {
            return false;
        }

        return $preference === 'watched'
            || $task->collaborators()->where('user_id', $user->id)->exists()
            || $task->updates()->active()->where('author_user_id', $user->id)->exists()
            || $task->updates()->active()->whereHas('mentions', fn ($mentions) => $mentions->where('user_id', $user->id))->exists();
    }

    /** @return array{string,string} */
    private function copy(string $kind, Task $task): array
    {
        return match ($kind) {
            'task.assigned' => ['Task assigned', "You were assigned {$task->title}."],
            'task.collaborator_added' => ['Added to a task', "You were added to {$task->title}."],
            'task.mentioned' => ['Mentioned in a task', "You were mentioned in {$task->title}."],
            'task.overdue' => ['Task overdue', "{$task->title} is overdue."],
            'task.due_reminder' => ['Task due soon', "{$task->title} is due soon."],
            default => ['Task changed', "{$task->title} was updated."],
        };
    }

    private function finish(string $intentId, string $token, string $status): void
    {
        TaskNotificationIntent::withoutGlobalScopes()
            ->whereKey($intentId)->where('claim_token', $token)->update([
                'status' => $status, 'claim_token' => null, 'claimed_until' => null,
                'last_error_code' => null, 'updated_at' => now(),
            ]);
    }
}
