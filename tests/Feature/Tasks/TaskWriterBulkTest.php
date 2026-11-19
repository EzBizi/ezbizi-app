<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskColumn;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk 11-18: a backfill through update() raised 9,282 automation events ahead of real missed calls. */
class TaskWriterBulkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Desk 11-19: a task with no group named goes to Open Tasks, never the board's first group (Company Info). */
    public function test_a_task_with_no_group_lands_in_open_tasks_not_the_first_group(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $board = app(TaskBoards::class)->ensureFor($t['franchise']);
            $board->groups()->update(['position' => DB::raw('position + 10')]);
            $board->groups()->create(['organization_id' => $t['org']->id, 'name' => 'Company Info', 'position' => 0]);
            $board->groups()->where('name', 'Open Tasks')->delete();

            $flag = app(TaskWriter::class)->create($board, ['title' => 'Address needs fixing'], null);
            $this->assertSame('Open Tasks', $flag->fresh()->group->name);
            $this->assertSame($flag->group_id, app(TaskWriter::class)->create($board, ['title' => 'Second'], null)->group_id, 'made once, then reused');
        });
    }

    public function test_a_bulk_write_raises_no_automation_event_and_the_app_still_does_after(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $board = app(TaskBoards::class)->ensureFor($t['franchise']);
            $col = TaskColumn::query()->create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Workiz Job ID', 'type' => 'text', 'position' => 1]);
            $task = app(TaskWriter::class)->create($board, ['title' => 'Job #1'], $t['admin']);
            $before = DB::table('automation_events')->count();

            TaskWriter::bulk(fn (TaskWriter $w) => $w->update($task, ['values' => [$col->id => '800021103']], null));
            $this->assertSame($before, DB::table('automation_events')->count(), 'the backfill raised no event');
            $this->assertSame('800021103', $task->values()->where('column_id', $col->id)->value('value_text'));

            // The app's own writes still raise theirs afterwards.
            app(TaskWriter::class)->update($task->fresh(), ['values' => [$col->id => '1']], $t['admin']);
            $this->assertGreaterThan($before, DB::table('automation_events')->count(), 'events are back for everyone else');
        });
    }
}
