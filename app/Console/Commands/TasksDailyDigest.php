<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily board update (Shoval 11-13): each person who owns open tasks gets one morning summary,
 * what is overdue, due today and open, with the first few by name.
 */
class TasksDailyDigest extends Command
{
    protected $signature = 'ezbizi:tasks-daily-digest';

    protected $description = 'Each owner of open tasks gets one morning summary of them';

    public function handle(TenantContext $tenant, NotificationDispatcher $bell): int
    {
        $sent = 0;
        foreach (Organization::query()->withoutGlobalScopes()->get() as $org) {
            $tenant->run($org, function () use ($bell, &$sent) {
                $today = now()->toDateString();
                $open = Task::query()->whereNull('completed_at')->whereNull('parent_task_id')
                    ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
                    ->get(['tasks.id', 'tasks.title', 'tasks.due_on', 'task_assignees.user_id as owner'])
                    ->groupBy('owner');
                foreach ($open as $owner => $tasks) {
                    $user = User::query()->where('is_active', true)->find($owner);
                    if ($user === null) {
                        continue;
                    }
                    $late = $tasks->filter(fn ($t) => $t->due_on && $t->due_on->toDateString() < $today);
                    $due = $tasks->filter(fn ($t) => $t->due_on && $t->due_on->toDateString() === $today);
                    $lines = $late->take(5)->map(fn ($t) => 'Overdue: '.$t->title)
                        ->concat($due->take(5)->map(fn ($t) => 'Due today: '.$t->title))->values()->all();
                    $sent += $bell->dispatch(
                        title: sprintf('Your tasks today: %d open, %d due today, %d overdue', $tasks->count(), $due->count(), $late->count()),
                        body: $lines === [] ? null : implode("\n", $lines),
                        onlyTo: $user, source: 'tasks', kind: 'task.digest',
                    );
                }
            });
        }
        $this->info("Sent {$sent}.");

        return self::SUCCESS;
    }
}
