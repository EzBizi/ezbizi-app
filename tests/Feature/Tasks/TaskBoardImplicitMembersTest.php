<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\TaskBoard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** People assigned at a company or franchise are on its board (Jose, 2026-10-28). */
class TaskBoardImplicitMembersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function board(array $tenant, string $kind, string $id): TaskBoard
    {
        return $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('kind', $kind)->where($kind.'_id', $id)->firstOrFail());
    }

    private function moveTo(array $tenant, User $user, array $scope): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $user, $scope) {
            Assignment::query()->where('user_id', $user->id)->delete();
            Assignment::create(['organization_id' => $tenant['org']->id, 'user_id' => $user->id, ...$scope]);
        });
    }

    private function people(User $as, TaskBoard $board): array
    {
        return $this->signedInAs($as)->getJson("/api/v1/task-boards/{$board->id}/members")->assertOk()->json('data.members');
    }

    public function test_a_company_level_person_is_on_the_company_board(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Staff', []);
        $this->moveTo($tenant, $user, ['assigned_company_id' => $tenant['company']->id]);
        $board = $this->board($tenant, 'company', $tenant['company']->id);

        $this->signedInAs($user)->getJson("/api/v1/task-boards/{$board->id}")->assertOk()
            ->assertJsonPath('data.my_role', 'member')
            ->assertJsonPath('data.members_count', 1);
        $this->assertContains($board->id, collect($this->signedInAs($user)->getJson('/api/v1/task-boards')->json('data'))->pluck('id'));

        $row = collect($this->people($tenant['admin'], $board))->firstWhere('user_id', $user->id);
        $this->assertSame(['id' => null, 'role' => 'member', 'implicit' => true], array_intersect_key($row, array_flip(['id', 'role', 'implicit'])));

        // Not on the franchise board below it: no cascade.
        $franchiseBoard = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $this->assertNotContains($user->id, collect($this->people($tenant['admin'], $franchiseBoard))->pluck('user_id'));
    }

    public function test_a_branch_level_person_sees_their_branch_board_not_a_sibling(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($tenant, 'Alpha F2');
        $user = $this->userWithRole($tenant, 'Tech', [], null, $tenant['franchise']);
        $mine = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $theirs = $this->board($tenant, 'franchise', $sibling->id);

        $this->signedInAs($user)->getJson("/api/v1/task-boards/{$mine->id}")->assertOk()->assertJsonPath('data.my_role', 'member');
        $this->signedInAs($user)->getJson("/api/v1/task-boards/{$theirs->id}")->assertNotFound();
        $this->assertNotContains($user->id, collect($this->people($tenant['admin'], $theirs))->pluck('user_id'));
    }

    public function test_removing_the_assignment_removes_the_membership(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Tech', [], null, $tenant['franchise']);
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $this->assertContains($user->id, collect($this->people($tenant['admin'], $board))->pluck('user_id'));

        $this->moveTo($tenant, $user, ['assigned_company_id' => $tenant['company']->id]);

        $this->assertNotContains($user->id, collect($this->people($tenant['admin'], $board))->pluck('user_id'));
        $this->signedInAs($user)->getJson("/api/v1/task-boards/{$board->id}")->assertNotFound();
    }

    public function test_a_manual_viewer_stays_a_viewer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit'], null, $tenant['franchise']);
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/task-boards/{$board->id}/members", ['user_id' => $user->id, 'role' => 'viewer'])
            ->assertCreated();

        $rows = collect($this->people($tenant['admin'], $board))->where('user_id', $user->id)->values();
        $this->assertCount(1, $rows);
        $this->assertSame(['viewer', false], [$rows[0]['role'], $rows[0]['implicit'] ?? false]);
        $this->signedInAs($user)->getJson("/api/v1/task-boards/{$board->id}")->assertOk()->assertJsonPath('data.my_role', 'viewer');
    }
}
