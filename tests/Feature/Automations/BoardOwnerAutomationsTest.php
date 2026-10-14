<?php

namespace Tests\Feature\Automations;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A board's owner automates their own board without the automations
 * permission (Jose, 2026-10-13: "yes they should") - and nothing else.
 */
class BoardOwnerAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_owner_without_the_permission_automates_their_board_and_nothing_else(): void
    {
        $t = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($t, 'Owner', ['tasks.view', 'tasks.edit']);
        $mine = $this->signedInAs($owner)->postJson('/api/v1/task-boards', [
            'name' => 'Mine', 'visibility' => 'main', 'franchise_id' => $t['franchise']->id,
        ])->assertCreated()->json('data.id');
        $theirs = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->where('kind', 'franchise')->value('id'));

        $rule = fn (string $board, array $over = []) => $over + [
            'name' => 'Done: tell the assignee', 'trigger_key' => 'task.status_changed',
            'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
            'conditions' => ['all' => [['field' => 'board_id', 'op' => 'eq', 'value' => $board]]],
            'actions' => [['type' => 'notify', 'message' => 'Done', 'to_assignee' => true]],
        ];

        $id = $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($mine))->assertCreated()->json('data.id');
        $this->signedInAs($owner)->patchJson("/api/v1/automations/{$id}", ['is_active' => true])->assertOk();

        $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($theirs))->assertForbidden();
        $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($mine, ['trigger_key' => 'job.created']))->assertUnprocessable(); // a module they cannot open; refused before the gate
        $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($mine, ['scope_type' => null, 'scope_id' => null]))->assertForbidden();
        $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($mine, ['conditions' => ['any' => [['field' => 'board_id', 'op' => 'eq', 'value' => $mine]]]]))->assertForbidden();
        $this->signedInAs($owner)->postJson('/api/v1/automations', $rule($mine, ['actions' => [['type' => 'task.create', 'title' => 'Not a board recipe']]]))->assertForbidden();
        // An edit cannot carry their rule off their board.
        $this->signedInAs($owner)->patchJson("/api/v1/automations/{$id}", ['conditions' => ['all' => [['field' => 'board_id', 'op' => 'eq', 'value' => $theirs]]]])->assertForbidden();

        $admin = $this->signedInAs($t['admin'])->postJson('/api/v1/automations', $rule($theirs))->assertCreated()->json('data.id');
        $this->assertSame([$id], collect($this->signedInAs($owner)->getJson('/api/v1/automations')->assertOk()->json('data'))->pluck('id')->all());
        $this->signedInAs($owner)->getJson("/api/v1/automations/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->signedInAs($owner)->getJson("/api/v1/automations/{$admin}")->assertForbidden();
        $this->signedInAs($owner)->deleteJson("/api/v1/automations/{$admin}")->assertForbidden();
        $this->signedInAs($owner)->deleteJson("/api/v1/automations/{$id}")->assertOk();
    }
}
