<?php

namespace Tests\Feature\Reports;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Tasks (Shoval's list via Task Board, 2026-11-20): per owner, open, done this week, overdue, days to done. */
class TasksReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_owner_open_done_in_the_week_overdue_and_days_to_done(): void
    {
        $this->travelTo('2026-11-19 15:00:00'); // Thursday; the week is 16-22 November
        $t = $this->provisionTenant('Alpha');
        $sam = $this->userWithRole($t, 'Office', ['tasks.view']);
        $this->inTenant($t['org'], function () use ($t, $sam) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->firstOrFail();
            $group = TaskGroup::query()->create(['board_id' => $board->id, 'name' => 'Open', 'position' => 1]);
            $task = function (string $title, array $owners, array $more = []) use ($board, $group, $t) {
                $task = Task::query()->create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'group_id' => $group->id, 'title' => $title] + $more);
                $task->forceFill(['created_at' => $more['created_at'] ?? '2026-11-01 14:00:00'])->save();
                foreach ($owners as $i => $user) {
                    DB::table('task_assignees')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
                        'task_id' => $task->id, 'user_id' => $user->id, 'position' => $i]);
                }

                return $task;
            };
            $late = $task('Late', [$t['admin']], ['due_on' => '2026-11-10']);
            $task('Not yet due', [$t['admin']], ['due_on' => '2026-11-25']);
            $task('Two days', [$sam], ['created_at' => '2026-11-16 14:00:00', 'completed_at' => '2026-11-18 14:00:00']);
            $task('Last week', [$sam], ['completed_at' => '2026-11-10 14:00:00']);
            $task('Both, one day', [$t['admin'], $sam], ['created_at' => '2026-11-16 14:00:00', 'completed_at' => '2026-11-17 14:00:00']);
            $task('Nobody on it', []);
            // Not counted: a subtask and a deleted task.
            $task('Subtask', [$t['admin']], ['parent_task_id' => $late->id, 'due_on' => '2026-11-01']);
            $task('Deleted', [$t['admin']], ['due_on' => '2026-11-01'])->delete();
        });

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/tasks')->assertOk()->json('data');

        $this->assertSame(['2026-11-16', '2026-11-22'], [$data['from'], $data['to']]);
        $this->assertSame([
            [$t['admin']->name, 2, 1, 1, 1],
            [null, 1, 0, 0, null],
            [$sam->name, 0, 2, 0, 1.5],
        ], array_map(fn (array $r) => [$r['name'], $r['open'], $r['done'], $r['overdue'], $r['avg_days_to_done']], $data['rows']));
        // Tasks, not the rows' sum: the shared one is done once.
        $this->assertSame(['open' => 3, 'overdue' => 1, 'done' => 2], $data['totals']);

        $lastWeek = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/tasks?from=2026-11-09&to=2026-11-15')->assertOk()->json('data.totals');
        $this->assertSame(1, $lastWeek['done']);
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Office', ['tasks.view', 'reports.view']))->getJson('/api/v1/reports/tasks')->assertForbidden();
    }
}
