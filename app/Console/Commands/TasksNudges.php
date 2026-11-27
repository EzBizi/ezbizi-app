<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shoval's 11-13 list for turning Monday off: a reminder set on a task rings its person's bell
 * when due, and a task nobody has updated for a week nudges its owners (once per quiet spell).
 */
class TasksNudges extends Command
{
    protected $signature = 'ezbizi:tasks-nudges';

    protected $description = 'Ring task reminders that are due, and nudge owners of tasks with no update for a week';

    /** ponytail: one week for everyone; a per-organization setting when someone asks for another length */
    public const QUIET_DAYS = 7;

    public function handle(TenantContext $tenant, NotificationDispatcher $bell): int
    {
        $rung = 0;
        $nudged = 0;
        foreach (Organization::query()->withoutGlobalScopes()->get() as $org) {
            $tenant->run($org, function () use ($bell, &$rung, &$nudged) {
                foreach (TaskReminder::query()->whereNull('sent_at')->where('remind_at', '<=', now())->with(['task', 'user'])->get() as $r) {
                    $r->forceFill(['sent_at' => now()])->save();
                    if ($r->task === null || $r->user === null) {
                        continue;
                    }
                    $rung += $bell->dispatch(title: 'Reminder: '.$r->task->title, body: $r->note, subject: $r->task, onlyTo: $r->user,
                        source: 'tasks', inAppOnly: false, kind: 'task.reminder');
                }

                $since = now()->subDays(self::QUIET_DAYS);
                $quiet = Task::query()->whereNull('completed_at')->whereNull('parent_task_id')
                    ->where('updated_at', '<', $since)
                    ->whereNotExists(fn ($q) => $q->from('task_updates')->whereColumn('task_updates.task_id', 'tasks.id')->where('task_updates.created_at', '>=', $since))
                    ->where(fn ($q) => $q->whereNull('stale_announced_at')->orWhereColumn('stale_announced_at', '<', 'updated_at'))
                    ->whereHas('owners')->with('owners')->limit(500)->get();
                foreach ($quiet as $task) {
                    DB::table('tasks')->where('id', $task->id)->update(['stale_announced_at' => now()]); // not a touch: updated_at stays
                    $nudged += $bell->dispatch(title: 'No update for '.self::QUIET_DAYS.' days: '.$task->title, subject: $task, source: 'tasks',
                        userIds: $task->owners->pluck('user_id')->map(fn ($id) => (string) $id)->all(), kind: 'task.stale');
                }
            });
        }
        $this->info("Reminders {$rung}, nudges {$nudged}.");

        return self::SUCCESS;
    }
}
