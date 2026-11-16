<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An open board patches itself from GET task-boards/{id}/changes (Jose,
 * 2026-11-16: a Monday status change showed only after leaving the tab).
 */
class TaskBoardChangesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_changes_since_a_cursor_holds_later_edits_and_deletions_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $tenant['franchise']->id)->first());
        $api = fn () => $this->signedInAs($tenant['admin']);
        $group = $this->inTenant($tenant['org'], fn () => (string) $board->groups->sortBy('position')->first()->id);
        $note = $api()->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Note', 'type' => 'text'])
            ->assertCreated()->json('data.id');

        Carbon::setTestNow('2026-11-16 10:00:00');
        $old = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'group_id' => $group, 'title' => 'Old'])
            ->assertCreated()->json('data.id');
        $edited = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'group_id' => $group, 'title' => 'Edited'])
            ->assertCreated()->json('data.id');
        $doomed = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'group_id' => $group, 'title' => 'Doomed'])
            ->assertCreated()->json('data.id');

        Carbon::setTestNow('2026-11-16 10:05:00');
        $cursor = $api()->getJson("/api/v1/task-boards/{$board->id}/changes?since=".urlencode('2026-11-16T10:04:00Z'))
            ->assertOk()->json('data.cursor');
        $this->assertNotNull($cursor);

        Carbon::setTestNow('2026-11-16 10:06:00');
        // A value-only edit lives in its own table and must still date the task.
        $api()->patchJson("/api/v1/tasks/{$edited}", ['values' => [$note => 'from Monday']])->assertOk();
        $api()->deleteJson("/api/v1/tasks/{$doomed}")->assertSuccessful();

        Carbon::setTestNow('2026-11-16 10:07:00');
        $data = $api()->getJson("/api/v1/task-boards/{$board->id}/changes?since=".urlencode($cursor))
            ->assertOk()->json('data');
        $ids = collect($data['tasks'])->pluck('id')->all();
        $this->assertContains($edited, $ids);
        $this->assertNotContains($old, $ids);
        $this->assertNotContains($doomed, $ids);
        $this->assertSame([$doomed], $data['deleted']);
        $row = collect($data['tasks'])->firstWhere('id', $edited);
        $this->assertSame('from Monday', $row['values'][$note]);
        $this->assertSame(2, $data['group_totals'][$group]['count']);
        $this->assertNotEmpty($data['groups']);
        $this->assertGreaterThan($cursor, $data['cursor']);

        // Nothing since: no tasks, no totals.
        $quiet = $api()->getJson("/api/v1/task-boards/{$board->id}/changes?since=".urlencode($data['cursor']))
            ->assertOk()->json('data');
        $this->assertSame([], $quiet['tasks']);
        $this->assertArrayNotHasKey('group_totals', $quiet);

        $api()->getJson("/api/v1/task-boards/{$board->id}/changes")->assertUnprocessable();
        Carbon::setTestNow();
    }

    public function test_another_tenant_cannot_read_a_boards_changes(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $board = $this->inTenant($alpha['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $alpha['franchise']->id)->first());

        $res = $this->signedInAs($beta['admin'])
            ->getJson("/api/v1/task-boards/{$board->id}/changes?since=2026-01-01T00:00:00Z");
        $this->assertContains($res->status(), [403, 404]);
    }
}
