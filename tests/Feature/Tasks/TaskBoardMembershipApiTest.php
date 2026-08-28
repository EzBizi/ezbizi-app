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

    public function test_readding_the_last_owner_cannot_demote_them(): void
    {
        $board = $this->createBoard('private');
        $owner = $this->ownerMembership($board);

        $this->postJson("/api/v1/tasks/boards/{$board->id}/members", [
            'user_id' => $this->alpha['admin']->id,
            'role' => 'member',
        ])->assertUnprocessable();

        $this->enterTenant();
        $this->assertSame('owner', $owner->fresh()->role->value);
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

    public function test_recovery_candidate_feed_is_exact_place_admin_only_and_content_blind(): void
    {
        $oldOwner = $this->user('Orphaned owner', ['tasks.view']);
        $replacement = $this->user('Eligible recovery owner', ['tasks.view']);
        $board = $this->directPrivateBoard($oldOwner, 'Never expose recovery board content');
        $oldOwner->forceFill(['is_active' => false])->save();

        $candidates = $this->getJson(
            "/api/v1/tasks/boards/{$board->id}/participants?purpose=membership&search=Eligible",
        )->assertOk()->assertJsonPath('data.0.id', $replacement->id);
        $this->assertStringNotContainsString('Never expose recovery board content', $candidates->getContent());
        $this->assertNotContains($this->alpha['admin']->id, collect($candidates->json('data'))->pluck('id'));

        $viewer = $this->user('Ordinary recovery viewer', ['tasks.view']);
        $viewer->forceFill([
            'preferences' => ['navigation.scope' => 'company:'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($viewer);
        $this->getJson("/api/v1/tasks/boards/{$board->id}/participants?purpose=membership")
            ->assertNotFound()->assertJsonMissing(['id' => $replacement->id]);

        $this->lookAt(ViewScope::FRANCHISE, $this->alpha['franchise']->id);
        $this->actingAs($this->alpha['admin']->fresh());
        $this->getJson("/api/v1/tasks/boards/{$board->id}/participants?purpose=membership")
            ->assertNotFound()->assertJsonMissing(['id' => $replacement->id]);
    }

    public function test_recovery_candidate_feed_hides_a_healthy_private_board_from_a_same_view_administrator(): void
    {
        $owner = $this->user('Healthy private owner', ['tasks.view']);
        $candidate = $this->user('Healthy private candidate', ['tasks.view']);
        $board = $this->directPrivateBoard($owner, 'Healthy private board');

        $this->getJson("/api/v1/tasks/boards/{$board->id}/participants?purpose=membership")
            ->assertNotFound()
            ->assertJsonMissing(['id' => $candidate->id]);
    }

    public function test_directory_actions_and_archived_and_recoverable_feeds_are_authoritative_and_private(): void
    {
        $active = $this->createBoard('private');

        $directory = $this->getJson('/api/v1/tasks/boards')->assertOk();
        $this->assertContains('create_board', $directory->json('meta.capabilities.actions'));
        $this->assertContains('archive', $directory->json('data.0.actions'));
        $this->assertContains('manage_members', $directory->json('data.0.actions'));

        $this->postJson("/api/v1/tasks/boards/{$active->id}/archive", [
            'expected_structure_version' => $active->structure_version,
        ])->assertOk();
        $archived = $this->getJson('/api/v1/tasks/boards?archived=1')->assertOk();
        $this->assertSame($active->id, $archived->json('data.0.id'));
        $this->assertSame(['restore'], $archived->json('data.0.actions'));

        $formerOwner = $this->user('Former recovery owner', ['tasks.view']);
        $replacement = $this->user('Recovery replacement', ['tasks.view']);
        $orphan = $this->directPrivateBoard($formerOwner, 'Never reveal this board name');
        $formerOwner->forceFill(['is_active' => false])->save();

        $recoverable = $this->getJson('/api/v1/tasks/boards?recoverable=1')->assertOk();
        $this->assertSame($orphan->id, $recoverable->json('data.0.id'));
        $this->assertSame(['recover_owner'], $recoverable->json('data.0.actions'));
        $this->assertStringNotContainsString('Never reveal this board name', $recoverable->getContent());
        $this->assertStringNotContainsString($replacement->email, $recoverable->getContent());

        $viewer = $this->user('Recovery viewer', ['tasks.view']);
        $this->actingAs($viewer);
        $this->getJson('/api/v1/tasks/boards?recoverable=1')->assertForbidden()
            ->assertJsonMissing(['id' => $orphan->id]);
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

    public function test_folder_and_membership_actions_match_the_writes_that_are_currently_safe(): void
    {
        $folder = $this->postJson('/api/v1/tasks/folders', ['name' => 'Administration'])
            ->assertCreated();
        $this->assertSame(['update', 'archive'], $folder->json('data.actions'));
        $folderId = $folder->json('data.id');
        $this->postJson("/api/v1/tasks/folders/{$folderId}/archive")->assertOk();
        $this->getJson('/api/v1/tasks/folders?archived=1')->assertOk()
            ->assertJsonPath('data.0.id', $folderId)
            ->assertJsonPath('data.0.actions', ['restore']);

        $board = $this->createBoard('private');
        $candidate = $this->user('Member actions candidate', ['tasks.view']);
        $candidateMembership = $this->postJson("/api/v1/tasks/boards/{$board->id}/members", [
            'user_id' => $candidate->id, 'role' => 'member',
        ])->assertCreated()->json('data.id');

        $members = collect($this->getJson("/api/v1/tasks/boards/{$board->id}/members")
            ->assertOk()->json('data'));
        $this->assertSame([], $members->firstWhere('user_id', $this->alpha['admin']->id)['actions']);
        $this->assertSame(
            ['change_role', 'revoke'],
            $members->firstWhere('id', $candidateMembership)['actions'],
        );

        $this->patchJson("/api/v1/tasks/boards/{$board->id}/members/{$candidateMembership}", [
            'role' => 'owner',
        ])->assertOk();
        $members = collect($this->getJson("/api/v1/tasks/boards/{$board->id}/members")
            ->assertOk()->json('data'));
        $this->assertSame(
            ['change_role', 'revoke'],
            $members->firstWhere('user_id', $this->alpha['admin']->id)['actions'],
        );
    }

    public function test_archived_directories_require_current_management_authority(): void
    {
        $folder = $this->postJson('/api/v1/tasks/folders', ['name' => 'Archived manager folder'])
            ->assertCreated();
        $this->postJson('/api/v1/tasks/folders/'.$folder->json('data.id').'/archive')->assertOk();

        $board = $this->createBoard('scope');
        $this->postJson("/api/v1/tasks/boards/{$board->id}/archive", [
            'expected_structure_version' => $board->structure_version,
        ])->assertOk();

        $viewer = $this->user('Archived directory viewer', ['tasks.view']);
        $viewer->forceFill([
            'preferences' => ['navigation.scope' => 'company:'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($viewer);
        $directory = $this->getJson('/api/v1/tasks/boards')->assertOk();
        $this->assertNotContains('view_archived', $directory->json('meta.capabilities.actions'));
        $this->getJson('/api/v1/tasks/boards?archived=1')->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/v1/tasks/folders?archived=1')->assertForbidden()
            ->assertJsonMissing(['name' => 'Archived manager folder']);
    }

    public function test_folder_directory_refuses_an_organization_fallback_outside_assignment_reach(): void
    {
        $this->postJson('/api/v1/tasks/folders', ['name' => 'Company secrets'])->assertCreated();
        $companyViewer = $this->user('Company only', ['tasks.view']);
        $companyViewer->forceFill(['preferences' => []])->save();
        $this->actingAs($companyViewer->fresh());

        $this->getJson('/api/v1/tasks/folders')->assertForbidden()
            ->assertJsonMissing(['name' => 'Company secrets']);
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
