<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Row 69: Monday's Archive item (restorable, in the board's Archive) and bulk Archive / Set column value. */
class TaskArchiveTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_archive_restore_and_bulk_set_value(): void
    {
        $t = $this->provisionTenant('Alpha');
        $api = fn () => $this->signedInAs($t['admin']);
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->firstOrFail());
        [$a, $b, $c] = array_map(fn ($n) => $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => $n])->assertCreated()->json('data.id'), ['A', 'B', 'C']);

        $api()->postJson("/api/v1/tasks/{$a}/archive")->assertOk();
        $this->assertSame([$a], collect($api()->getJson("/api/v1/task-boards/{$board->id}/archived")->assertOk()->json('data'))->pluck('id')->all());
        $this->inTenant($t['org'], fn () => $this->assertNull(Task::query()->find($a), 'off the board'));
        $api()->postJson("/api/v1/tasks/{$a}/restore")->assertOk();
        $this->inTenant($t['org'], fn () => $this->assertNull(Task::query()->find($a)->archived_at));
        $this->assertSame([], $api()->getJson("/api/v1/task-boards/{$board->id}/archived")->json('data'));

        $col = $this->inTenant($t['org'], fn () => TaskColumn::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => 'Area', 'type' => 'text', 'position' => 3]));
        $api()->postJson('/api/v1/tasks/bulk', ['ids' => [$b, $c], 'op' => 'values', 'values' => [$col->id => 'North']])->assertOk()->assertJsonPath('data.refused', []);
        $this->inTenant($t['org'], fn () => $this->assertSame(['North', 'North'], \App\Models\TaskColumnValue::query()->where('column_id', $col->id)->pluck('value_text')->all()));

        $api()->postJson('/api/v1/tasks/bulk', ['ids' => [$b, $c], 'op' => 'archive'])->assertOk();
        $this->assertCount(2, $api()->getJson("/api/v1/task-boards/{$board->id}/archived")->json('data'));
    }
}
