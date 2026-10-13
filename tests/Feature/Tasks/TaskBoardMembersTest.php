<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Custom boards and monday-style members (inbox 0120). */
class TaskBoardMembersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function privateBoard(array $tenant, $owner): string
    {
        return $this->signedInAs($owner)->postJson('/api/v1/task-boards', [
            'name' => 'Secret project',
            'visibility' => 'private',
            'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'custom')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.my_role', 'owner')
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.can_manage', true)
            ->json('data.id');
    }

    public function test_a_custom_board_is_seeded_like_an_automatic_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit']);
        $id = $this->privateBoard($tenant, $owner);

        $this->inTenant($tenant['org'], function () use ($id) {
            $this->assertSame(['This week', 'Next week', 'Done'], TaskGroup::query()->where('board_id', $id)->orderBy('position')->pluck('name')->all());
            $this->assertSame(['not_started', 'working_on_it', 'stuck', 'done'], TaskStatus::query()->where('board_id', $id)->orderBy('position')->pluck('key')->all());
        });

        $row = collect($this->signedInAs($owner)->getJson('/api/v1/task-boards')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(['visibility' => 'private', 'my_role' => 'owner', 'owner' => ['id' => $owner->id, 'name' => 'Owner']],
            array_intersect_key($row, array_flip(['visibility', 'my_role', 'owner'])));
    }

    public function test_a_private_board_is_hidden_from_non_members_and_a_viewer_cannot_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit']);
        $other = $this->userWithRole($tenant, 'Other', ['tasks.view', 'tasks.edit']);
        $id = $this->privateBoard($tenant, $owner);

        $this->signedInAs($other)->getJson("/api/v1/task-boards/{$id}")->assertNotFound();
        $this->assertNotContains($id, collect($this->signedInAs($other)->getJson('/api/v1/task-boards')->json('data'))->pluck('id'));

        $memberId = $this->signedInAs($owner)->postJson("/api/v1/task-boards/{$id}/members", ['user_id' => $other->id, 'role' => 'viewer'])
            ->assertCreated()->assertJsonPath('data.role', 'viewer')->json('data.id');

        $this->signedInAs($other)->getJson("/api/v1/task-boards/{$id}")->assertOk()
            ->assertJsonPath('data.my_role', 'viewer')
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.can_edit', false);
        $this->signedInAs($other)->postJson('/api/v1/tasks', ['board_id' => $id, 'title' => 'Nope'])->assertForbidden();
        // A viewer does not manage members either.
        $this->signedInAs($other)->patchJson("/api/v1/task-boards/{$id}/members/{$memberId}", ['role' => 'owner'])->assertForbidden();

        $this->signedInAs($owner)->patchJson("/api/v1/task-boards/{$id}/members/{$memberId}", ['role' => 'member'])->assertOk();
        $this->signedInAs($other)->postJson('/api/v1/tasks', ['board_id' => $id, 'title' => 'Yes'])->assertCreated();

        // The Administrator sees every private board.
        $this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$id}")->assertOk();
    }

    public function test_the_last_owner_cannot_be_removed_or_demoted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit']);
        $id = $this->privateBoard($tenant, $owner);
        $other = $this->userWithRole($tenant, 'Other', ['tasks.view']);
        $local = $this->userWithRole($tenant, 'Elsewhere', ['tasks.view'], onlyAt: $this->inTenant($tenant['org'], fn () => \App\Models\Franchise::create(['company_id' => $tenant['company']->id, 'name' => 'Alpha F2'])));
        $list = $this->signedInAs($owner)->getJson("/api/v1/task-boards/{$id}/members")->assertOk();
        $mine = $list->json('data.members.0.id');
        $this->assertSame(['user_id' => $owner->id, 'name' => 'Owner', 'role' => 'owner'], array_diff_key($list->json('data.members.0'), ['id' => 1]));
        $candidates = collect($list->json('data.candidates'));
        $this->assertSame('Other', $candidates->firstWhere('id', $other->id)['role_name']);
        $this->assertNull($candidates->firstWhere('id', $owner->id));   // already a member
        $this->assertNull($candidates->firstWhere('id', $local->id));   // cannot reach Alpha F1
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/task-boards/{$id}/members", ['user_id' => $local->id, 'role' => 'member'])->assertStatus(422);

        $this->signedInAs($owner)->deleteJson("/api/v1/task-boards/{$id}/members/{$mine}")->assertStatus(422);
        $this->signedInAs($owner)->patchJson("/api/v1/task-boards/{$id}/members/{$mine}", ['role' => 'member'])->assertStatus(422);
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, TaskBoardMember::query()->where('board_id', $id)->where('role', 'owner')->count()));
    }

    public function test_assigning_a_non_member_on_a_private_board_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit']);
        $other = $this->userWithRole($tenant, 'Other Person', ['tasks.view']);
        $id = $this->privateBoard($tenant, $owner);

        $this->signedInAs($owner)->postJson('/api/v1/tasks', ['board_id' => $id, 'title' => 'X', 'assignee_user_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.assignee_user_id.0', 'Add Other Person to this board first.');

        $taskId = $this->signedInAs($owner)->postJson('/api/v1/tasks', ['board_id' => $id, 'title' => 'X'])->assertCreated()->json('data.id');
        $this->signedInAs($owner)->patchJson("/api/v1/tasks/{$taskId}", ['assignee_user_id' => $other->id])->assertStatus(422);

        $this->signedInAs($owner)->postJson("/api/v1/task-boards/{$id}/members", ['user_id' => $other->id, 'role' => 'member'])->assertCreated();
        $this->signedInAs($owner)->patchJson("/api/v1/tasks/{$taskId}", ['assignee_user_id' => $other->id])->assertOk();
    }

    public function test_a_board_outside_the_current_view_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $local = $this->userWithRole($tenant, 'Local', ['tasks.view', 'tasks.edit'], onlyAt: $tenant['franchise']);

        // A franchise view cannot file on its company, nor on the organization.
        $this->signedInAs($local)->postJson('/api/v1/task-boards', ['name' => 'Up', 'visibility' => 'main', 'company_id' => $tenant['company']->id])
            ->assertStatus(422)->assertJsonValidationErrors('place');
        $this->signedInAs($local)->postJson('/api/v1/task-boards', ['name' => 'Org', 'visibility' => 'main'])
            ->assertStatus(422);
        $this->signedInAs($local)->postJson('/api/v1/task-boards', ['name' => 'Here', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id])
            ->assertCreated();
    }

    public function test_only_custom_boards_are_renamed_or_deleted_and_only_by_an_owner(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit']);
        $other = $this->userWithRole($tenant, 'Other', ['tasks.view', 'tasks.edit']);
        $id = $this->signedInAs($owner)->postJson('/api/v1/task-boards', ['name' => 'Shared', 'visibility' => 'main'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($other)->getJson("/api/v1/task-boards/{$id}")->assertOk()->assertJsonPath('data.my_role', null);
        $this->signedInAs($other)->deleteJson("/api/v1/task-boards/{$id}")->assertForbidden();
        $this->signedInAs($owner)->patchJson("/api/v1/task-boards/{$id}", ['name' => 'Renamed', 'visibility' => 'private'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.visibility', 'private');
        $this->signedInAs($other)->getJson("/api/v1/task-boards/{$id}")->assertNotFound();

        $auto = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $this->signedInAs($tenant['admin'])->deleteJson("/api/v1/task-boards/{$auto->id}")->assertStatus(422);

        $this->signedInAs($owner)->deleteJson("/api/v1/task-boards/{$id}")->assertNoContent();
        $this->signedInAs($owner)->getJson("/api/v1/task-boards/{$id}")->assertNotFound();
        $this->inTenant($tenant['org'], fn () => $this->assertNotNull(TaskBoard::withTrashed()->find($id)?->deleted_at));
    }
}
