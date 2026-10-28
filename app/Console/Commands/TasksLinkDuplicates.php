<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * One-off (Jose, 2026-10-28): the same automation-filed task sits on a
 * company board and on one of its branches' boards as two rows, so Done on
 * one left the other open. This LINKS each such pair (Done / reopen then
 * carry across); it never deletes. Dry run unless --apply.
 *
 * A pair: a company-board task and a branch-board task under that company,
 * both filed by an automation, same title (trimmed, any case) and same
 * About record (or both none). Top-level tasks only.
 */
class TasksLinkDuplicates extends Command
{
    protected $signature = 'ezbizi:link-duplicate-tasks {organization_id} {--apply}';

    protected $description = 'Link company-board tasks to the branch-board task they duplicate (dry run unless --apply; never deletes)';

    public function handle(TenantContext $tenant): int
    {
        $org = Organization::query()->withoutGlobalScopes()->find($this->argument('organization_id'));
        if ($org === null) {
            $this->error('No organization with that id.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        return $tenant->run($org, function () use ($apply) {
            $key = fn (Task $t) => mb_strtolower(trim($t->title)).'|'.$t->about_type.'|'.$t->about_id;
            $pairs = 0;
            $linked = 0;

            foreach (TaskBoard::query()->where('kind', TaskBoard::KIND_COMPANY)->get() as $company) {
                $branchBoards = TaskBoard::query()->where('kind', TaskBoard::KIND_FRANCHISE)
                    ->whereIn('franchise_id', Franchise::query()->withTrashed()->where('company_id', $company->company_id)->select('id'))
                    ->pluck('id');
                if ($branchBoards->isEmpty()) {
                    continue;
                }
                $top = fn ($q) => $q->whereNull('parent_task_id')->whereNotNull('created_by_automation_id');
                $branchTasks = Task::query()->whereIn('board_id', $branchBoards)->where($top)->get()->groupBy($key);

                foreach (Task::query()->where('board_id', $company->id)->where($top)->orderBy('created_at')->get() as $task) {
                    foreach ($branchTasks->get($key($task), collect()) as $twin) {
                        $pairs++;
                        if (TaskLinks::exists($task, $twin)) {
                            continue;
                        }
                        $this->line("{$task->id} <-> {$twin->id}  {$task->title}");
                        if ($apply) {
                            TaskLinks::link($task, $twin, null);
                        }
                        $linked++;
                    }
                }
            }

            $this->line(($apply ? '' : '[dry run, nothing saved] ')."pairs: {$pairs}, ".($apply ? 'linked' : 'would link').": {$linked}");

            return self::SUCCESS;
        });
    }
}
