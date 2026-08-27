<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\Role;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskBoardMembershipApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->lookAt(ViewScope::COMPANY, $this->alpha['company']->id);
        $this->actingAs($this->alpha['admin']->fresh());
    }

    public function test_owner_managed_membership_preserves_an_eligible_owner(): void
    {
        $board = $this->createBoard('private');
        $candidate = $this->user('Candidate', ['tasks.view']);

        $membershipId = $this->postJson("/api/v1/tasks/boards/{$board->id}/members", [
            'user_id' => $candidate->id,
            'role' => 'member',
        ])->assertCreated()->assertJsonPath('data.user_id', $candidate->id)->json('data.id');

        $this->deleteJson("/api/v1/tasks/boards/{$board->id}/members/".$this->ownerMembership($board)->id)
            ->assertUnprocessable();

        $this->patchJson("/api/v1/tasks/boards/{$board->id}/members/{$membershipId}", [
            'role' => 'owner',
        ])->assertOk()->assertJsonPath('data.role', 'owner');

        $owner = $this->ownerMembership($board, $this->alpha['admin']);
        $this->deleteJson("/api/v1/tasks/boards/{$board->id}/members/{$owner->id}")
            ->assertOk()->assertJsonPath('data.active', false);
        $this->enterTenant();
        $this->assertTrue(TaskBoardMember::active()->where('board_id', $board->id)
            ->where('user_id', $candidate->id)->where('role', 'owner')->exists());
    }

    public function test_recovery_endpoint_is_content_blind_and_cannot_install_the_acting_administrator(): void
    {
        $oldOwner = $this->user('Former owner', ['tasks.view']);
        $replacement = $this->user('Replacement owner', ['tasks.view']);
        $board = $this->directPrivateBoard($oldOwner, 'Confidential acquisition plan');
        $oldOwner->forceFill(['is_active' => false])->save();

        $this->postJson("/api/v1/tasks/boards/{$board->id}/recovery", [
            'new_owner_user_id' => $this->alpha['admin']->id,
        ])->assertForbidden();

        $response = $this->postJson("/api/v1/tasks/boards/{$board->id}/recovery", [
            'new_owner_user_id' => $replacement->id,
        ])->assertOk()
            ->assertJsonPath('data.board_id', $board->id)
            ->assertJsonPath('data.owner_user_id', $replacement->id);

        $this->assertStringNotContainsString('Confidential acquisition plan', $response->getContent());
    }

    public function test_folders_favorites_and_participant_search_stay_at_the_current_place(): void
    {
        $candidate = $this->user('Available person', ['tasks.view']);
        $folderId = $this->postJson('/api/v1/tasks/folders', ['name' => 'Operations'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->alpha['company']->id)
            ->assertJsonPath('data.franchise_id', null)
            ->json('data.id');

        $board = $this->createBoard('private', $folderId);
        $this->putJson("/api/v1/tasks/boards/{$board->id}/preference", [
            'is_favorite' => true,
            'mark_opened' => true,
        ])->assertOk()->assertJsonPath('data.is_favorite', true);

        $this->getJson("/api/v1/tasks/boards/{$board->id}/participants?purpose=membership&search=Available")
            ->assertOk()->assertJsonPath('data.0.id', $candidate->id);

        $this->lookAt(ViewScope::FRANCHISE, $this->alpha['franchise']->id);
        $this->actingAs($this->alpha['admin']->fresh());
        $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Wrong-place folder',
            'visibility' => 'scope',
            'folder_id' => $folderId,
        ])->assertUnprocessable();
    }

    private function createBoard(string $visibility, ?string $folderId = null): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', array_filter([
            'name' => 'Operations',
            'visibility' => $visibility,
            'folder_id' => $folderId,
        ]))->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskBoard::findOrFail($id);
    }

    private function user(string $name, array $permissions): User
    {
        $this->enterTenant();
        $role = Role::create(['name' => $name, 'is_system' => false]);
        $role->syncPermissionKeys($permissions);
        $user = User::create([
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@alpha.test',
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
        Assignment::create(['user_id' => $user->id, 'assigned_company_id' => $this->alpha['company']->id]);

        return $user->fresh(['role.permissions', 'assignments.assignedCompany']);
    }

    private function ownerMembership(TaskBoard $board, ?User $user = null): TaskBoardMember
    {
        $this->enterTenant();

        return TaskBoardMember::query()->where('board_id', $board->id)
            ->where('user_id', ($user ?? $this->alpha['admin'])->id)->firstOrFail();
    }

    private function directPrivateBoard(User $owner, string $name): TaskBoard
    {
        $this->enterTenant();
        $board = new TaskBoard;
        $board->forceFill([
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => null,
            'name' => $name,
            'visibility' => 'private',
            'position' => 20,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ])->save();
        TaskBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        return $board;
    }

    private function lookAt(string $type, ?string $id = null): void
    {
        $this->enterTenant();
        $value = $type === ViewScope::ORGANIZATION ? null : $type.':'.$id;
        $this->alpha['admin']->forceFill(['preferences' => array_filter(['navigation.scope' => $value])])->save();
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
