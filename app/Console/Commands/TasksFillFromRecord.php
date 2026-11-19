<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\TaskRecordFill;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Backfill the board's own columns (Area, Reason, Address, Client name,
 * Phone number) on the open tasks some rules made, from each task's lead and
 * its job (Jose, 2026-11-19). Empty cells only; Resolution never.
 */
class TasksFillFromRecord extends Command
{
    protected $signature = 'ezbizi:tasks-fill-from-record {--org=} {--as=} {--rule=* : automation ids whose tasks to fill} {--group= : also every open task in groups whose name contains this} {--all-open : every open task on every board} {--review=* : of those, the rules whose tasks are bad reviews} {--redo-reason : first clear a Reason no person has touched (ours: the rule or the Description move), so it is worked out per task} {--dry}';

    protected $description = 'Fill Area, Reason, Address, Client name and Phone on rule-made tasks from their lead and job';

    public function handle(TenantContext $tenant, TaskRecordFill $fill): int
    {
        $org = Organization::withoutGlobalScopes()->findOrFail($this->option('org'));

        return $tenant->run($org, function () use ($fill) {
            $actor = User::query()->findOrFail($this->option('as'));
            $counts = ['tasks' => 0, 'filled' => 0, 'cells' => 0];
            $before = \App\Models\AutomationEvent::query()->count();
            $checked = false;
            Task::query()->whereNull('completed_at')->where(fn ($q) => $q->whereIn('created_by_automation_id', (array) $this->option('rule'))
                ->when($this->option('group'), fn ($q, $g) => $q->orWhereHas('group', fn ($x) => $x->where('name', 'like', '%'.$g.'%')))
                ->when($this->option('all-open'), fn ($q) => $q->orWhereNotNull('id')))
                ->with('board')->chunkById(200, function ($tasks) use ($fill, $actor, &$counts, &$checked, $before) {
                    // The first batch's events are read before the rest runs (RetardMoments 11-18): any, and it stops.
                    if (! $checked && $counts['tasks'] > 0) {
                        $checked = true;
                        $raised = \App\Models\AutomationEvent::query()->count() - $before;
                        if ($raised > 0) {
                            throw new \RuntimeException("The first batch raised {$raised} automation event(s); stopped.");
                        }
                    }
                    foreach ($tasks as $task) {
                        $counts['tasks']++;
                        $about = (string) $task->about_type;
                        $lead = $task->about_id && str_contains(strtolower($about), 'lead') ? Lead::query()->withoutGlobalScopes()->find($task->about_id) : null;
                        // A job: the task is about it, or names it in its "EzBizi Job #" column.
                        $job = $task->about_id && str_contains(strtolower($about), 'job') ? \App\Models\Job::query()->withoutGlobalScopes()->find($task->about_id) : null;
                        $jobCol = $task->board->columns()->where('name', 'like', 'EzBizi Job%')->first();
                        if ($job === null && $jobCol !== null && ($no = trim((string) $task->values()->where('column_id', $jobCol->id)->value('value_text'))) !== '') {
                            $job = \App\Models\Job::query()->withoutGlobalScopes()->where('number', $no)->orWhere('number', 'JOB-'.preg_replace('/^JOB-/i', '', $no))->first();
                        }
                        $review = in_array($task->created_by_automation_id, (array) $this->option('review'), true);
                        if ($this->option('dry')) {
                            continue;
                        }
                        if ($this->option('redo-reason') && ! $review && $task->created_by_automation_id !== null && ($reason = $task->board->columns()->where('name', 'Reason')->first()) !== null
                            && ! \Illuminate\Support\Facades\DB::table('task_activities')->where('task_id', $task->id)->where('field', $reason->id)
                                ->where('user_id', '!=', $actor->id)->exists()) {
                            $task->values()->where('column_id', $reason->id)->delete();
                            $counts['reasons_cleared'] = ($counts['reasons_cleared'] ?? 0) + 1;
                        }
                        // Silent: no automation events (desk 11-19: 3,333 events held up missed calls), Monday still queued.
                        $set = \App\Support\Tasks\TaskWriter::bulk(fn ($w) => $fill->fill($task, $lead, $actor, $review, $job, $w), toMonday: true);
                        $counts['filled'] += $set === [] ? 0 : 1;
                        $counts['cells'] += count($set);
                    }
                });
            $this->info(json_encode($counts));

            return self::SUCCESS;
        });
    }
}
