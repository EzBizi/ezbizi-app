<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Assignee mode (Jose, 2026-11-09, monday's NW board): each person edits only their own tasks. */
class TaskBoardAssigneeModeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_in_assignee_mode_only_owners_makers_and_managers_change_a_task(): void
    {
        $t = $this->provisionTenant('Alpha');
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first());
        $staff = $this->userWithRole($t, 'Staff', ['tasks.view', 'tasks.create', 'tasks.edit']);
        $other = $this->userWithRole($t, 'Staff 2', ['tasks.view', 'tasks.create', 'tasks.edit']);

        $theirs = $this->signedInAs($t['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Call back', 'assignee_user_id' => $other->id])
            ->assertCreated()->json('data.id');
        $boss = $this->signedInAs($t['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Admin only'])
            ->assertCreated()->json('data.id');

        // Everyone mode: anybody who can edit the board changes any task.
        $this->signedInAs($staff)->patchJson("/api/v1/tasks/{$boss}", ['title' => 'x'])->assertOk();

        // Only a manager switches the mode; Staff cannot.
        $this->signedInAs($staff)->patchJson("/api/v1/task-boards/{$board->id}", ['edit_mode' => 'assignee'])->assertForbidden();
        $this->signedInAs($t['admin'])->patchJson("/api/v1/task-boards/{$board->id}", ['edit_mode' => 'assignee'])
            ->assertOk()->assertJsonPath('data.edit_mode', 'assignee')->assertJsonPath('data.can_edit_all', true);

        $this->signedInAs($staff)->getJson("/api/v1/task-boards/{$board->id}")->assertJsonPath('data.can_edit_all', false);
        $this->signedInAs($staff)->patchJson("/api/v1/tasks/{$theirs}", ['title' => 'mine now'])->assertForbidden();
        $this->signedInAs($other)->patchJson("/api/v1/tasks/{$theirs}", ['title' => 'Called'])->assertOk();
        $this->signedInAs($t['admin'])->patchJson("/api/v1/tasks/{$theirs}", ['title' => 'Checked'])->assertOk();

        // Adding stays open, and what you made is yours to change.
        $made = $this->signedInAs($staff)->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'New lead'])->assertCreated()->json('data.id');
        $this->signedInAs($staff)->patchJson("/api/v1/tasks/{$made}", ['title' => 'New lead, called'])->assertOk();
    }
}
