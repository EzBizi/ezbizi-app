<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Raise task.overdue once per task: due before today, not done. The stamp
 * (overdue_announced_at) is what makes it once; moving the due date clears it
 * in TaskWriter, so a re-dated task can be overdue again.
 */
class TasksOverdue extends Command
{
    protected $signature = 'ezbizi:tasks-overdue';

    protected $description = 'Raise task.overdue for tasks past their due date';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $raised = 0;

        $due = Task::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('overdue_announced_at')
            ->where('status', '!=', Task::STATUS_DONE)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString())
            ->get();

        foreach ($due->groupBy('organization_id') as $organizationId => $tasks) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($tasks, $events, &$raised) {
                foreach ($tasks as $task) {
                    DB::transaction(function () use ($task, $events) {
                        $task->forceFill(['overdue_announced_at' => now()])->save();
                        $events->emitAfterCommit('task.overdue', $task, [
                            'assignee_user_id' => $task->assignee_user_id,
                            'after' => [
                                'board_id' => $task->board_id,
                                'title' => $task->title,
                                'status' => $task->status,
                                'due_on' => $task->due_on?->toDateString(),
                            ],
                        ]);
                    });
                    $raised++;
                }
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }
}
