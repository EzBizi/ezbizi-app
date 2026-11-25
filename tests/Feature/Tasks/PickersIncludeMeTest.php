<?php

namespace Tests\Feature\Tasks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 11-25: the signed-in person is always in the Task Board's people pickers and @mention search. */
class PickersIncludeMeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_i_find_myself_on_a_board_whose_people_list_leaves_me_out(): void
    {
        $t = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($t, 'Boss', ['tasks.view', 'tasks.edit', 'tasks.view_all'], 'boss@alpha.test');
        // A private board of the admin's: its people are its members, and the boss is not one.
        $board = $this->signedInAs($t['admin'])->postJson('/api/v1/task-boards', ['name' => 'Private plans', 'visibility' => 'private'])
            ->assertCreated()->json('data.id');
        $task = $this->signedInAs($t['admin'])->postJson('/api/v1/tasks', ['board_id' => $board, 'title' => 'Plan'])->assertCreated()->json('data.id');

        $people = collect($this->signedInAs($boss)->getJson("/api/v1/task-boards/{$board}")->assertOk()->json('data.assignable'))->pluck('name');
        $this->assertContains('Boss', $people, 'the owner/assignee picker and the person filter');

        $mentionable = collect($this->signedInAs($boss)->getJson("/api/v1/mentionable?type=task&id={$task}")->assertOk()->json('data'))->pluck('name');
        $this->assertContains('Boss', $mentionable, '@mention search');
    }
}
