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
    protected $signature = 'ezbizi:tasks-fill-from-record {--org=} {--as=} {--rule=* : automation ids whose tasks to fill} {--group= : also every open task in groups whose name contains this} {--review=* : of those, the rules whose tasks are bad reviews} {--redo-reason : first clear a Reason no person has touched (ours: the rule or the Description move), so it is worked out per task} {--dry}';

    protected $description = 'Fill Area, Reason, Address, Client name and Phone on rule-made tasks from their lead and job';

    public function handle(TenantContext $tenant, TaskRecordFill $fill): int
    {
        $org = Organization::withoutGlobalScopes()->findOrFail($this->option('org'));

        return $tenant->run($org, function () use ($fill) {
            $actor = User::query()->findOrFail($this->option('as'));
            $counts = ['tasks' => 0, 'filled' => 0, 'cells' => 0];
            Task::query()->whereNull('completed_at')->where(fn ($q) => $q->whereIn('created_by_automation_id', (array) $this->option('rule'))
                ->when($this->option('group'), fn ($q, $g) => $q->orWhereHas('group', fn ($x) => $x->where('name', 'like', '%'.$g.'%'))))
                ->with('board')->chunkById(200, function ($tasks) use ($fill, $actor, &$counts) {
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
                        $set = $fill->fill($task, $lead, $actor, $review, $job);
                        $counts['filled'] += $set === [] ? 0 : 1;
                        $counts['cells'] += count($set);
                    }
                });
            $this->info(json_encode($counts));

            return self::SUCCESS;
        });
    }
}
