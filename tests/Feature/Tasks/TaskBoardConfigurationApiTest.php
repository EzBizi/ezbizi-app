<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskGroup;
use App\Models\TaskView;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskBoardConfigurationApiTest extends TestCase
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
        $this->alpha['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($this->alpha['admin']->fresh());
    }

    public function test_board_creation_uses_the_exact_active_view_and_installs_the_complete_defaults(): void
    {
        $response = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations',
            'visibility' => 'private',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Operations')
            ->assertJsonPath('data.company_id', $this->alpha['company']->id)
            ->assertJsonPath('data.franchise_id', null)
            ->assertJsonPath('data.structure_version', 1);

        $this->enterTenant();
        $board = TaskBoard::findOrFail($response->json('data.id'));
        $this->assertSame(['Completed', 'To do'], $board->groups()->orderBy('name')->pluck('name')->all());
        $this->assertSame(5, $board->statusOptions()->count());
        $this->assertSame(4, $board->priorityOptions()->count());
        $this->assertSame(6, $board->coreColumnSettings()->count());
        $this->assertTrue($board->views()->where('is_permanent_main', true)->exists());
        $this->assertTrue(TaskBoardMember::active()->where('board_id', $board->id)
            ->where('user_id', $this->alpha['admin']->id)->where('role', 'owner')->exists());

        $this->getJson('/api/v1/tasks/boards')->assertOk()
            ->assertJsonPath('data.0.id', $board->id);

        $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Forged place',
            'visibility' => 'scope',
            'company_id' => $this->alpha['company']->id,
        ])->assertUnprocessable();
        $this->enterTenant();
        $this->assertSame(1, TaskBoard::count());
    }

    public function test_configuration_writes_are_versioned_and_preserve_structural_invariants(): void
    {
        $board = $this->createBoard();

        $groupResponse = $this->postJson("/api/v1/tasks/boards/{$board->id}/groups", [
            'name' => 'Waiting',
            'color' => '#B05F00',
            'expected_structure_version' => 1,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 2);
        $groupId = $groupResponse->json('data.id');

        $this->patchJson("/api/v1/tasks/boards/{$board->id}/groups/{$groupId}", [
            'name' => 'Stale rename',
            'expected_structure_version' => 1,
        ])->assertConflict()->assertJsonPath('meta.current_structure_version', 2);
        $this->enterTenant();
        $this->assertSame('Waiting', TaskGroup::findOrFail($groupId)->name);

        $this->patchJson("/api/v1/tasks/boards/{$board->id}/groups/{$groupId}", [
            'name' => 'Pending customer',
            'expected_structure_version' => 2,
        ])->assertOk()->assertJsonPath('meta.structure_version', 3);

        $statusId = $this->postJson("/api/v1/tasks/boards/{$board->id}/statuses", [
            'name' => 'Review',
            'color' => '#1769AA',
            'category' => 'active',
            'expected_structure_version' => 3,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 4)->json('data.id');

        $this->patchJson("/api/v1/tasks/boards/{$board->id}/statuses/{$statusId}", [
            'category' => 'blocked',
            'expected_structure_version' => 4,
        ])->assertOk()->assertJsonPath('meta.structure_version', 5);

        $columnId = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => 'Estimate',
            'type' => 'number',
            'number_suffix' => 'hours',
            'expected_structure_version' => 5,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 6)->json('data.id');
        $this->enterTenant();
        $this->assertSame('hours', TaskColumnDefinition::findOrFail($columnId)->number_suffix);

        $completed = $board->statusOptions()->where('category', StatusCategory::Completed)->firstOrFail();
        $this->postJson("/api/v1/tasks/boards/{$board->id}/statuses/{$completed->id}/archive", [
            'expected_structure_version' => 6,
        ])->assertUnprocessable();
        $this->enterTenant();
        $this->assertSame(6, $board->fresh()->structure_version);

        $defaultGroup = $board->groups()->where('name', 'To do')->firstOrFail();
        $defaultStatus = $board->statusOptions()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $task = new Task;
        $task->forceFill([
            'organization_id' => $this->alpha['org']->id,
            'board_id' => $board->id,
            'group_id' => $defaultGroup->id,
            'number' => 'TASK-1',
            'title' => 'Active work',
            'status_option_id' => $defaultStatus->id,
            'created_by' => $this->alpha['admin']->id,
            'updated_by' => $this->alpha['admin']->id,
            'position' => 0,
        ])->save();

        $this->postJson("/api/v1/tasks/boards/{$board->id}/groups/{$defaultGroup->id}/archive", [
            'expected_structure_version' => 6,
        ])->assertUnprocessable();
        $this->enterTenant();
        $this->assertNull($defaultGroup->fresh()->archived_at);

        $labelColumnId = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => 'Workstream',
            'type' => 'label',
            'expected_structure_version' => 6,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 7)->json('data.id');
        $this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$labelColumnId}/options", [
            'name' => 'Installation',
            'color' => '#00AEEF',
            'expected_structure_version' => 7,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 8);

        $this->enterTenant();
        $ordered = $board->groups()->active()->orderByDesc('position')->pluck('id')->all();
        $this->postJson("/api/v1/tasks/boards/{$board->id}/groups/reorder", [
            'ordered_ids' => $ordered,
            'expected_structure_version' => 8,
        ])->assertOk()->assertJsonPath('meta.structure_version', 9);
        $this->enterTenant();
        $this->assertSame($ordered, $board->groups()->active()->orderBy('position')->pluck('id')->all());
    }

    public function test_structure_only_duplication_and_board_archive_restore_copy_no_work_or_members(): void
    {
        $source = $this->createBoard('Source');

        $duplicate = $this->postJson("/api/v1/tasks/boards/{$source->id}/duplicate", [
            'name' => 'Source copy',
            'expected_structure_version' => 1,
        ])->assertCreated()->assertJsonPath('data.name', 'Source copy');
        $this->enterTenant();
        $copy = TaskBoard::findOrFail($duplicate->json('data.id'));

        $this->assertSame($source->groups()->count(), $copy->groups()->count());
        $this->assertSame($source->statusOptions()->count(), $copy->statusOptions()->count());
        $this->assertSame(0, $copy->tasks()->count());
        $this->assertSame(1, $copy->members()->count());
        $this->assertSame($this->alpha['company']->id, $copy->company_id);

        $this->postJson("/api/v1/tasks/boards/{$source->id}/duplicate", [
            'name' => 'Forbidden data copy',
            'include_tasks' => true,
            'expected_structure_version' => 1,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/tasks/boards/{$copy->id}/archive", [
            'expected_structure_version' => 1,
        ])->assertOk()->assertJsonPath('data.archived', true)
            ->assertJsonPath('meta.structure_version', 2);
        $this->postJson("/api/v1/tasks/boards/{$copy->id}/restore", [
            'expected_structure_version' => 2,
        ])->assertOk()->assertJsonPath('data.archived', false)
            ->assertJsonPath('meta.structure_version', 3);

        $this->patchJson("/api/v1/tasks/boards/{$copy->id}", [
            'company_id' => $this->alpha['company']->id,
            'expected_structure_version' => 3,
        ])->assertUnprocessable();
    }

    public function test_archived_board_is_unavailable_except_through_owner_restore(): void
    {
        $board = $this->createBoard('Archived');
        $this->postJson("/api/v1/tasks/boards/{$board->id}/archive", [
            'expected_structure_version' => 1,
        ])->assertOk();

        $this->getJson("/api/v1/tasks/boards/{$board->id}")->assertNotFound();
        $this->postJson("/api/v1/tasks/boards/{$board->id}/groups", [
            'name' => 'Backdoor',
            'expected_structure_version' => 2,
        ])->assertNotFound();
        $this->postJson("/api/v1/tasks/boards/{$board->id}/restore", [
            'expected_structure_version' => 2,
        ])->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_moving_a_board_to_a_populated_folder_appends_without_position_collision(): void
    {
        $folderId = $this->postJson('/api/v1/tasks/folders', ['name' => 'Destination'])
            ->assertCreated()->json('data.id');
        $destination = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Already there', 'visibility' => 'scope', 'folder_id' => $folderId,
        ])->assertCreated()->json('data.id');
        $moving = $this->createBoard('Moving');

        $this->patchJson("/api/v1/tasks/boards/{$moving->id}", [
            'folder_id' => $folderId,
            'expected_structure_version' => 1,
        ])->assertOk()->assertJsonPath('data.folder_id', $folderId);

        $this->enterTenant();
        $positions = TaskBoard::query()->whereIn('id', [$destination, $moving->id])
            ->orderBy('position')->pluck('position')->all();
        $this->assertSame([1, 2], $positions);
    }

    public function test_board_detail_hides_archived_groups_and_other_users_personal_views(): void
    {
        $board = $this->createBoard('Visible configuration');
        $other = $this->userWithRole($this->alpha, 'Other viewer', ['tasks.view']);
        $this->enterTenant();
        $group = $board->groups()->firstOrFail();
        $group->forceFill(['archived_at' => now()])->save();
        $view = new TaskView;
        $view->forceFill([
            'board_id' => $board->id,
            'owner_user_id' => $other->id,
            'visibility' => 'personal',
            'name' => 'Private plan',
            'is_locked' => false,
            'is_permanent_main' => false,
            'configuration' => ['schema' => 1],
            'position' => 1,
        ])->save();

        $response = $this->getJson("/api/v1/tasks/boards/{$board->id}")->assertOk();
        $this->assertNotContains($group->id, collect($response->json('data.groups'))->pluck('id')->all());
        $this->assertNotContains('Private plan', collect($response->json('data.views'))->pluck('name')->all());
    }

    private function createBoard(string $name = 'Operations'): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', [
            'name' => $name,
            'visibility' => 'scope',
        ])->assertCreated()->json('data.id');

        $this->enterTenant();

        return TaskBoard::findOrFail($id);
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
