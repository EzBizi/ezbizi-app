<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk for Jose, 11-17: a dispatcher's complaint the AI already filed. */
class TaskSameCallerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_another_open_task_in_the_group_for_the_same_phone_is_found(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$ai, $mine, $elsewhere, $plain] = $this->inTenant($t['org'], function () use ($t) {
            $board = app(TaskBoards::class)->ensureFor($t['franchise']);
            $cx = TaskGroup::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Customer Experience', 'position' => 9]);
            $other = TaskGroup::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Other', 'position' => 10]);
            $phone = TaskColumn::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Phone number', 'type' => 'text', 'position' => 1]);
            $w = app(TaskWriter::class);

            return [
                $w->create($board, ['title' => 'Complaint: late tech', 'group_id' => $cx->id, 'values' => [$phone->id => '+1 (732) 555-0101']], null),
                $w->create($board, ['title' => 'Call 732.555.0101 back', 'group_id' => $cx->id], $t['admin']),
                $w->create($board, ['title' => 'Call 7325550101', 'group_id' => $other->id], $t['admin']),
                $w->create($board, ['title' => 'Order parts', 'group_id' => $cx->id], $t['admin']),
            ];
        });
        $as = $this->signedInAs($t['admin']);

        $this->assertSame($ai->id, $as->getJson("/api/v1/tasks/{$mine->id}/same-caller")->assertOk()->json('data.id'));
        // Another group, or no phone: nothing.
        $this->assertNull($as->getJson("/api/v1/tasks/{$elsewhere->id}/same-caller")->json('data'));
        $this->assertNull($as->getJson("/api/v1/tasks/{$plain->id}/same-caller")->json('data'));

        // Older than 3 days: no longer a duplicate.
        $this->inTenant($t['org'], fn () => Task::query()->whereKey($ai->id)->update(['created_at' => now()->subDays(4)]));
        $this->assertNull($as->getJson("/api/v1/tasks/{$mine->id}/same-caller")->json('data'));
    }
}
