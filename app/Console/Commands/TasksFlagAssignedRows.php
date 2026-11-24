<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\Task;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-off (desk 11-23): the last 30 days' Workiz flag tasks listed under each
 * owner's "Assigned to me", as the flag alert now is. Already read: no push,
 * no email, nothing rings. Never twice.
 */
class TasksFlagAssignedRows extends Command
{
    protected $signature = 'ezbizi:tasks-flag-assigned-rows {--dry-run}';

    protected $description = "List the last 30 days' flag tasks under their owners' Assigned to me";

    public function handle(TenantContext $tenant): int
    {
        $n = 0;
        foreach (Organization::withoutGlobalScopes()->get() as $org) {
            $tenant->run($org, function () use (&$n) {
                Task::query()->with('board')->where('created_by_system', 'flags')->where('created_at', '>=', now()->subDays(30))
                    ->chunkById(200, function ($tasks) use (&$n) {
                        foreach ($tasks as $task) {
                            foreach ($task->owners()->pluck('user_id') as $userId) {
                                if (Notification::query()->where('user_id', $userId)->where('subject_id', $task->id)->where('kind', 'task.assigned')->exists()) {
                                    continue;
                                }
                                $n++;
                                if ($this->option('dry-run')) {
                                    continue;
                                }
                                Notification::insert([[
                                    'id' => (string) Str::uuid7(), 'organization_id' => $task->organization_id, 'user_id' => $userId,
                                    'source' => 'tasks', 'kind' => 'task.assigned', 'title' => $task->title,
                                    'body' => 'On '.($task->board?->name ?? 'the Task Board'), 'urgent' => false,
                                    'subject_type' => Task::class, 'subject_id' => $task->id, 'automation_id' => null, 'event_id' => null,
                                    'read_at' => now(), 'created_at' => $task->created_at,
                                ]]);
                            }
                        }
                    });
            });
        }
        $this->line(($this->option('dry-run') ? '[dry run] ' : '')."rows: $n");

        return self::SUCCESS;
    }
}
