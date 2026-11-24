<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Monday's scheduled update (gap review, 11-24): at its time a scheduled
 * update is posted as one written then: it goes to Monday, and its task's
 * followers and the people it names are told. Every minute.
 */
class TasksPostScheduledUpdates extends Command
{
    protected $signature = 'ezbizi:tasks-post-scheduled-updates';

    protected $description = 'Post the task updates scheduled for now';

    public function handle(TenantContext $tenant): int
    {
        $n = 0;
        foreach (TaskUpdate::query()->withoutGlobalScopes()->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())->get() as $update) {
            $org = Organization::query()->withoutGlobalScopes()->find($update->organization_id);
            if ($org === null) {
                continue;
            }
            $tenant->run($org, function () use ($update) {
                $update->forceFill(['scheduled_for' => null])->saveQuietly();
                $task = Task::query()->find($update->task_id);
                if ($task === null) {
                    return;
                }
                app(MondayOutbox::class)->record($task, [], $update);
                TaskWriter::tellMentioned($update, (array) ($update->mentions ?? []));
                TaskWriter::tellFollowers($update, (array) ($update->mentions ?? []));
            });
            $n++;
        }
        $this->line("posted: $n");

        return self::SUCCESS;
    }
}
