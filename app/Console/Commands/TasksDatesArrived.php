<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskColumnValue;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Raise task.date_arrived: a task's due date, or a date in one of its date
 * columns, is today (days_until 0) or tomorrow (days_until 1), in the
 * organization's timezone. Monday's "when Date arrives" and "when Date
 * arrives, one day before" (parity audit 2026-11-13). Once per task, date
 * field, date and days_until: a date moved to another day arrives again.
 *
 * The hour Monday's recipe named ("at 1pm") is the rule's own delay
 * (delay_minutes from midnight): the sweep runs hourly and raises the event
 * at the first run of the day, so a 780-minute delay runs the steps at 1pm.
 */
class TasksDatesArrived extends Command
{
    protected $signature = 'ezbizi:tasks-dates-arrived';

    protected $description = 'Raise task.date_arrived for task dates that are today or tomorrow';

    public function handle(TenantContext $tenant, EventEmitter $events, SettingsResolver $settings): int
    {
        $raised = 0;
        $orgs = Task::query()->withoutGlobalScopes()->whereNull('deleted_at')->distinct()->pluck('organization_id');

        foreach ($orgs as $organizationId) {
            $org = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($org === null) {
                continue;
            }
            $tenant->run($org, function () use ($org, $events, $settings, &$raised) {
                $zone = (string) ($settings->get('timezone', $org) ?: config('app.timezone'));
                $today = CarbonImmutable::now($zone)->startOfDay();
                $days = [$today->toDateString() => 0, $today->addDay()->toDateString() => 1];

                $hits = [];
                foreach (Task::query()->with('board')->whereIn('due_on', array_keys($days))->get() as $task) {
                    $hits[] = [$task, 'due', 'Due date', $task->due_on->toDateString()];
                }
                $values = TaskColumnValue::query()->with(['column', 'task.board'])
                    ->whereIn('value_date', array_keys($days))
                    ->whereHas('column', fn ($q) => $q->where('type', 'date'))->get();
                foreach ($values as $v) {
                    if ($v->task !== null) {
                        $hits[] = [$v->task, $v->column_id, $v->column->name, $v->value_date->toDateString()];
                    }
                }

                foreach ($hits as [$task, $columnId, $name, $date]) {
                    $daysUntil = $days[$date];
                    if (! Cache::add("task-date-arrived:{$task->id}:{$columnId}:{$date}:{$daysUntil}", true, now()->addDays(3))) {
                        continue;
                    }
                    $events->emit('task.date_arrived', $task, [
                        'assignee_user_id' => $task->assignee_user_id,
                        'board_id' => $task->board_id,
                        'board_name' => $task->board?->name,
                        'after' => [
                            'board_id' => $task->board_id,
                            'group_id' => $task->group_id,
                            'title' => $task->title,
                            'status' => $task->statusRow?->name ?? $task->status,
                            'column_id' => $columnId,
                            'column' => $name,
                            'date' => $date,
                            'days_until' => $daysUntil,
                            'due_on' => $task->due_on?->toDateString(),
                        ],
                    ]);
                    $raised++;
                }
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }
}
