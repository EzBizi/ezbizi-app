<?php

namespace App\Support\Tasks;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskDueReminderOccurrence;
use App\Models\TaskNotificationIntent;
use App\Models\TaskOverdueOccurrence;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TaskDueWorker
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly PreferenceRegistry $preferences,
        private readonly TaskBoardAccess $access,
        private readonly EventEmitter $events,
    ) {}

    public function overdue(int $limit = 500): int
    {
        return $this->eachCandidate('overdue', $limit, fn (Task $task) => $this->observeOverdue($task));
    }

    public function reminders(int $limit = 500): int
    {
        return $this->eachCandidate('reminder', $limit, fn (Task $task) => $this->observeReminder($task));
    }

    private function eachCandidate(string $kind, int $limit, callable $observe): int
    {
        $query = Task::withoutGlobalScopes()->join('task_status_options as worker_status', function ($join): void {
            $join->on('worker_status.organization_id', '=', 'tasks.organization_id')
                ->on('worker_status.board_id', '=', 'tasks.board_id')
                ->on('worker_status.id', '=', 'tasks.status_option_id');
        })->whereNull('tasks.archived_at')->whereNull('worker_status.archived_at')
            ->whereNotNull('tasks.due_on')
            ->whereNotIn('worker_status.category', [StatusCategory::Completed->value, StatusCategory::Cancelled->value])
            ->orderBy('tasks.due_on')->orderBy('tasks.id')->limit(max(1, min($limit, 5000)));
        if ($kind === 'reminder') {
            $query->whereBetween('tasks.due_on', [now()->subDay()->toDateString(), now()->addDays(4)->toDateString()]);
        } else {
            $query->where('tasks.due_on', '<=', now()->addDay()->toDateString());
        }

        $observed = 0;
        foreach ($query->pluck('tasks.organization_id', 'tasks.id') as $taskId => $organizationId) {
            $organization = Organization::query()->find($organizationId);
            if ($organization === null || $organization->isReadOnly()) {
                continue;
            }
            $observed += $this->runCandidate($organization, function () use ($taskId, $observe): int {
                $task = Task::query()->active()->with([
                    'board.company', 'board.franchise', 'board.organization', 'owner', 'statusOption',
                ])->find($taskId);
                if ($task === null || $task->board === null || $task->board->archived_at !== null
                    || $task->statusOption === null || $task->statusOption->archived_at !== null
                    || in_array($task->statusOption->category, [StatusCategory::Completed, StatusCategory::Cancelled], true)) {
                    return 0;
                }

                return $observe($task);
            });
        }

        return $observed;
    }

    /**
     * Per-item resilience (fix round 2, task-6 — see TaskRecurrenceWorker's
     * copy of this method for the full rationale): one candidate's uncaught
     * exception must not abort the rest of the batch. A failure is reported
     * (so it stays visible, not silently swallowed) and counted as zero for
     * this run; the occurrence that produced it is untouched, so the SAME
     * candidate is picked up again next run — exactly the same retry story
     * as any other skipped candidate.
     */
    private function runCandidate(Organization $organization, callable $work): int
    {
        try {
            return $this->tenant->run($organization, $work);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function observeOverdue(Task $task): int
    {
        $now = Carbon::now($this->timezone($task));
        if ($task->due_on === null || $task->due_on->format('Y-m-d') >= $now->toDateString()) {
            return 0;
        }

        return DB::transaction(function () use ($task): int {
            $occurrence = TaskOverdueOccurrence::query()->firstOrCreate([
                'task_id' => $task->id,
                'due_revision' => (int) $task->due_revision,
            ], [
                'board_id' => $task->board_id,
                'due_on' => $task->due_on->format('Y-m-d'),
                'created_at' => now(),
            ]);
            if (! $occurrence->wasRecentlyCreated) {
                return 0;
            }
            $this->events->emit('task.overdue', $task, [
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'owner_user_id' => $task->owner_user_id,
                'due_on' => $task->due_on->format('Y-m-d'),
                'due_revision' => (int) $task->due_revision,
            ]);
            if ($task->owner !== null && $this->mayNotify($task, $task->owner)) {
                $this->intent($task, $task->owner, 'task.overdue', $occurrence->id);
            }

            return 1;
        });
    }

    private function observeReminder(Task $task): int
    {
        if ($task->owner === null || ! $this->mayNotify($task, $task->owner)
            || ! $this->settings->get('tasks.due_reminders_enabled', $task->board->organization)) {
            return 0;
        }
        $leadHours = $this->leadHours($task->owner);
        $timezone = $this->timezone($task);
        $now = Carbon::now($timezone);
        $end = Carbon::parse($task->due_on->format('Y-m-d'), $timezone)->endOfDay();
        if ($now->lt($end->copy()->subHours($leadHours)) || $now->gt($end)) {
            return 0;
        }

        return DB::transaction(function () use ($task, $leadHours): int {
            $occurrence = TaskDueReminderOccurrence::query()->firstOrCreate([
                'task_id' => $task->id,
                'recipient_user_id' => $task->owner_user_id,
                'due_revision' => (int) $task->due_revision,
                'lead_hours' => $leadHours,
            ], [
                'board_id' => $task->board_id,
                'due_on' => $task->due_on->format('Y-m-d'),
                'created_at' => now(),
            ]);
            if (! $occurrence->wasRecentlyCreated) {
                return 0;
            }
            $this->intent($task, $task->owner, 'task.due_reminder', $occurrence->id);

            return 1;
        });
    }

    private function mayNotify(Task $task, User $recipient): bool
    {
        return $recipient->is_active && $this->access->backgroundViewDecision($recipient, $task->board)->allowed();
    }

    private function leadHours(User $user): int
    {
        $value = ($user->preferences ?? [])['tasks.due_reminder_hours']
            ?? $this->preferences->all()['tasks.due_reminder_hours']['default'];
        try {
            return (int) $this->preferences->cast('tasks.due_reminder_hours', $value);
        } catch (InvalidArgumentException) {
            return 24;
        }
    }

    private function timezone(Task $task): string
    {
        $scope = $task->board->franchise ?? $task->board->company ?? $task->board->organization;

        return (string) $this->settings->get('timezone', $scope);
    }

    private function intent(Task $task, User $recipient, string $kind, string $occurrenceId): void
    {
        TaskNotificationIntent::query()->firstOrCreate([
            'recipient_user_id' => $recipient->id,
            'kind' => $kind,
            'source_occurrence_key' => $occurrenceId,
        ], [
            'board_id' => $task->board_id,
            'task_id' => $task->id,
            'available_at' => now(),
        ]);
    }
}
