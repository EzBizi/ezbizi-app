<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A task's Activity log (Jose, 2026-11-09, monday's): which field, from what to what, by whom. */
class TaskActivityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_change_is_logged_in_words_and_read_newest_first(): void
    {
        $t = $this->provisionTenant('Alpha');
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first());
        $api = $this->signedInAs($t['admin']);
        $reason = $api->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Reason', 'type' => 'dropdown',
            'settings' => ['options' => [['label' => 'Complaint'], ['label' => 'Refund']]]])->assertCreated()->json('data');
        [$complaint, $refund] = array_column($reason['settings']['options'], 'id');
        $id = $api->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Call Ann'])->assertCreated()->json('data.id');

        $api->patchJson("/api/v1/tasks/{$id}", ['title' => 'Call Ann back', 'due_on' => '2026-11-20', 'values' => [$reason['id'] => $complaint]])->assertOk();
        $api->patchJson("/api/v1/tasks/{$id}", ['status' => 'done', 'values' => [$reason['id'] => $refund]])->assertOk();
        $api->patchJson("/api/v1/tasks/{$id}", ['title' => 'Call Ann back'])->assertOk(); // no change, no row

        $rows = $api->getJson("/api/v1/tasks/{$id}/activity")->assertOk()->json('data');
        $seen = array_map(fn ($r) => [$r['label'], $r['from'], $r['to']], $rows);
        $this->assertContains(['Task', 'Call Ann', 'Call Ann back'], $seen);
        $this->assertContains(['Due date', null, '2026-11-20'], $seen);
        $this->assertContains(['Reason', null, 'Complaint'], $seen);
        $this->assertContains(['Reason', 'Complaint', 'Refund'], $seen);
        $this->assertContains('Done', array_column($rows, 'to'));
        $this->assertCount(5, $rows);
        $this->assertSame($t['admin']->name, $rows[0]['who']);
    }
}
