<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A huge board pages per group: the first N top-level tasks of each group,
 * the group's full count and its number footers over every task, and a
 * Show more endpoint (NW - Nationwide: 24,713 tasks, 20 MB, Jose 2026-11-16).
 */
class TaskBoardPagingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_per_group_pages_a_board_and_group_tasks_fetches_the_rest(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $tenant['franchise']->id)->first());
        $api = fn () => $this->signedInAs($tenant['admin']);
        $group = $this->inTenant($tenant['org'], fn () => (string) $board->groups->sortBy('position')->first()->id);
        $cost = $api()->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Cost', 'type' => 'number'])
            ->assertCreated()->json('data.id');

        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $api()->postJson('/api/v1/tasks', [
                'board_id' => $board->id, 'group_id' => $group, 'title' => "T{$i}", 'values' => [$cost => $i],
            ])->assertCreated()->json('data.id');
        }
        // A subtask of the first rides along with it and is not counted.
        $sub = $api()->postJson('/api/v1/tasks', [
            'board_id' => $board->id, 'group_id' => $group, 'parent_task_id' => $ids[0], 'title' => 'Sub', 'values' => [$cost => 100],
        ])->assertCreated()->json('data.id');

        // Without per_group: every task, no totals (unchanged).
        $all = $api()->getJson("/api/v1/task-boards/{$board->id}")->assertOk()->json('data');
        $this->assertCount(8, $all['tasks']);
        $this->assertArrayNotHasKey('group_totals', $all);

        $paged = $api()->getJson("/api/v1/task-boards/{$board->id}?per_group=3")->assertOk()->json('data');
        $shown = collect($paged['tasks'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([...array_slice($ids, 0, 3), $sub], $shown);
        $totals = $paged['group_totals'][$group];
        $this->assertSame(7, $totals['count']);
        $this->assertSame(3, $totals['shown']);
        $this->assertTrue($totals['has_more']);
        // 1+…+7 over all seven, not the three on screen, and not the subtask.
        $this->assertEquals(28, $totals['footers'][$cost]['sum']);
        $this->assertEquals(4, $totals['footers'][$cost]['average']);

        $more = $api()->getJson("/api/v1/task-boards/{$board->id}/group-tasks?group_id={$group}&offset=3&limit=3")
            ->assertOk()->json();
        $this->assertSame(array_slice($ids, 3, 3), collect($more['data'])->pluck('id')->all());
        $this->assertSame(6, $more['meta']['shown']);
        $this->assertTrue($more['meta']['has_more']);

        $last = $api()->getJson("/api/v1/task-boards/{$board->id}/group-tasks?group_id={$group}&offset=6&limit=3")
            ->assertOk()->json();
        $this->assertSame([$ids[6]], collect($last['data'])->pluck('id')->all());
        $this->assertFalse($last['meta']['has_more']);
    }
}
