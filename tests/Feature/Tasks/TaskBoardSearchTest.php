<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Natalie 11-24 (01a20df2): the board search finds any column, done tasks too. */
class TaskBoardSearchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_search_finds_a_task_by_any_column_open_or_done_and_nothing_else(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $api = fn () => $this->signedInAs($tenant['admin']);
        $job = $api()->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Job #', 'type' => 'text'])->assertCreated()->json('data.id');
        $stage = $api()->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Stage', 'type' => 'dropdown',
            'settings' => ['options' => [['id' => 'o1', 'label' => 'Waiting Parts'], ['id' => 'o2', 'label' => 'Booked']]]])->assertCreated()->json('data.id');
        $make = fn (string $title, array $values) => $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => $title, 'values' => $values])->assertCreated()->json('data.id');
        $byJob = $make('Albert', [$job => '600-025-660']);
        $byStage = $make('Bea', [$stage => 'o1']);
        $other = $make('Carl', [$job => '123', $stage => 'o2']);
        $api()->patchJson("/api/v1/tasks/{$byJob}", ['status' => 'Done'])->assertOk();

        $find = fn (string $q) => collect($api()->getJson("/api/v1/task-boards/{$board->id}/search?q=".urlencode($q))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$byJob], $find('600025660'), 'digits find a formatted number, on a done task');
        $this->assertSame([$byJob], $find('025-660'));
        $this->assertSame([$byStage], $find('waiting'), 'an option by its label');
        $this->assertSame([$other], $find('Carl'));
        $this->assertSame([], $find('nobody'));
    }
}
