<?php

namespace Tests\Feature\Tasks;

use App\Models\Franchise;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-14: members on every kind of board, and three role ticks —
 * tasks.view_all, tasks.manage_members, tasks.create_boards.
 */
class TaskBoardPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function personalBoardOf(array $tenant, $user): string
    {
        $this->signedInAs($user)->getJson('/api/v1/task-boards')->assertOk();

        return $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('kind', 'user')->where('owner_user_id', $user->id)->value('id'));
    }

    private function privateBoardOf(array $tenant, $user): string
    {
        return $this->signedInAs($user)->postJson('/api/v1/task-boards', [
            'name' => 'Secret', 'visibility' => 'private', 'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()->json('data.id');
    }

    private function listIds($user): array
    {
        return collect($this->signedInAs($user)->getJson('/api/v1/task-boards')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_the_grant_migration_keeps_board_creation_for_roles_with_tasks_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Editor', ['tasks.view', 'tasks.edit']);
        $viewer = $this->userWithRole($tenant, 'Viewer', ['tasks.view']);

        (require database_path('migrations/2026_10_14_224518_grant_tasks_edit_roles_create_boards.php'))->up();

        $keys = fn ($user) => \Illuminate\Support\Facades\DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_id', $user->role_id)->pluck('key')->all();

        $this->assertContains('tasks.create_boards', $keys($editor));
        $this->assertNotContains('tasks.view_all', $keys($editor));
        $this->assertNotContains('tasks.manage_members', $keys($editor));
        $this->assertNotContains('tasks.create_boards', $keys($viewer));
    }

    public function test_view_all_shows_other_peoples_personal_and_private_boards(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $stranger = $this->userWithRole($tenant, 'Stranger', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $personal = $this->personalBoardOf($tenant, $stranger);
        $private = $this->privateBoardOf($tenant, $stranger);

        $without = $this->userWithRole($tenant, 'Without', ['tasks.view']);
        $with = $this->userWithRole($tenant, 'With', ['tasks.view', 'tasks.view_all']);

        $this->assertNotContains($personal, $this->listIds($without));
        $this->assertNotContains($private, $this->listIds($without));
        $this->signedInAs($without)->getJson("/api/v1/task-boards/{$personal}")->assertNotFound();

        $rows = collect($this->signedInAs($with)->getJson('/api/v1/task-boards')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['id' => $stranger->id, 'name' => 'Stranger'], $rows[$personal]['owner']);
        $this->assertSame('everyone', $rows[$personal]['section']);
        $this->assertSame('everyone', $rows[$private]['section']);
        $this->signedInAs($with)->getJson("/api/v1/task-boards/{$personal}")->assertOk()->assertJsonPath('data.can_edit', false);
    }

    public function test_manage_members_lets_a_non_owner_add_people(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $target = $this->userWithRole($tenant, 'Target', ['tasks.view']);
        $place = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->value('id'));

        $without = $this->userWithRole($tenant, 'Without', ['tasks.view', 'tasks.edit']);
        $with = $this->userWithRole($tenant, 'With', ['tasks.view', 'tasks.manage_members']);

        $this->signedInAs($without)->getJson("/api/v1/task-boards/{$place}")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->signedInAs($without)->postJson("/api/v1/task-boards/{$place}/members", ['user_id' => $target->id, 'role' => 'member'])->assertForbidden();
        $this->assertSame([], $this->signedInAs($without)->getJson("/api/v1/task-boards/{$place}/members")->json('data.candidates'));

        $this->signedInAs($with)->getJson("/api/v1/task-boards/{$place}")->assertOk()->assertJsonPath('data.can_manage', true);
        $row = collect($this->signedInAs($with)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $place);
        $this->assertTrue($row['can_manage']);
        $this->assertSame('place', $row['section']);
        $this->signedInAs($with)->postJson("/api/v1/task-boards/{$place}/members", ['user_id' => $target->id, 'role' => 'member'])->assertCreated();
    }

    public function test_create_boards_gates_creating_and_tasks_edit_alone_no_longer_does(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $without = $this->userWithRole($tenant, 'Without', ['tasks.view', 'tasks.edit']);
        $with = $this->userWithRole($tenant, 'With', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $body = ['name' => 'New', 'visibility' => 'main', 'franchise_id' => $tenant['franchise']->id];

        $this->signedInAs($without)->postJson('/api/v1/task-boards', $body)->assertForbidden();
        $this->signedInAs($with)->postJson('/api/v1/task-boards', $body)->assertCreated()
            ->assertJsonPath('data.members_preview.0', ['id' => $with->id, 'name' => 'With']);
    }

    public function test_an_administrator_who_is_not_a_member_sees_and_shares_a_strangers_boards(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $stranger = $this->userWithRole($tenant, 'Stranger', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $guest = $this->userWithRole($tenant, 'Guest', ['tasks.view', 'tasks.edit']);
        $personal = $this->personalBoardOf($tenant, $stranger);
        $private = $this->privateBoardOf($tenant, $stranger);
        $admin = $tenant['admin'];

        $ids = $this->listIds($admin);
        $this->assertContains($personal, $ids);
        $this->assertContains($private, $ids);

        foreach ([$personal, $private] as $id) {
            $this->signedInAs($admin)->getJson("/api/v1/task-boards/{$id}")->assertOk()->assertJsonPath('data.can_manage', true);
            $this->signedInAs($admin)->postJson("/api/v1/task-boards/{$id}/members", ['user_id' => $guest->id, 'role' => 'member'])->assertCreated();
        }
        $this->signedInAs($guest)->getJson("/api/v1/task-boards/{$private}")->assertOk()->assertJsonPath('data.can_edit', true);
    }

    public function test_a_place_board_member_without_reach_sees_and_edits_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->inTenant($tenant['org'], fn () => Franchise::create(['company_id' => $tenant['company']->id, 'name' => 'Alpha F2']));
        $local = $this->userWithRole($tenant, 'Local', ['tasks.view', 'tasks.edit'], onlyAt: $elsewhere);
        $place = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->value('id'));

        $this->signedInAs($local)->getJson("/api/v1/task-boards/{$place}")->assertNotFound();
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/task-boards/{$place}/members", ['user_id' => $local->id, 'role' => 'member'])->assertCreated();

        $this->signedInAs($local)->getJson("/api/v1/task-boards/{$place}")->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.my_role', 'member');
        $this->assertSame('shared', collect($this->signedInAs($local)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $place)['section']);
        $this->signedInAs($local)->postJson('/api/v1/tasks', ['board_id' => $place, 'title' => 'From afar'])->assertCreated();
    }

    public function test_a_personal_board_owner_shares_it_and_stays_its_owner(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view']);
        $friend = $this->userWithRole($tenant, 'Friend', ['tasks.view']);
        $board = $this->personalBoardOf($tenant, $owner);

        $this->signedInAs($friend)->getJson("/api/v1/task-boards/{$board}")->assertNotFound();
        $this->signedInAs($owner)->getJson("/api/v1/task-boards/{$board}")->assertOk()->assertJsonPath('data.can_manage', true);
        $this->signedInAs($owner)->postJson("/api/v1/task-boards/{$board}/members", ['user_id' => $friend->id, 'role' => 'member'])->assertCreated();

        $show = $this->signedInAs($friend)->getJson("/api/v1/task-boards/{$board}")->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_manage', false);
        $this->assertEqualsCanonicalizing([$owner->id, $friend->id], collect($show->json('data.assignable'))->pluck('id')->all());
        $this->signedInAs($friend)->postJson('/api/v1/tasks', ['board_id' => $board, 'title' => 'Shared', 'assignee_user_id' => $owner->id])->assertCreated();

        $mine = collect($this->signedInAs($owner)->getJson("/api/v1/task-boards/{$board}/members")->json('data.members'))->firstWhere('user_id', $owner->id);
        if ($mine !== null) {
            $this->signedInAs($owner)->deleteJson("/api/v1/task-boards/{$board}/members/{$mine['id']}")->assertStatus(422);
        }
        $this->assertSame('mine', collect($this->signedInAs($owner)->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $board)['section']);
    }
}
