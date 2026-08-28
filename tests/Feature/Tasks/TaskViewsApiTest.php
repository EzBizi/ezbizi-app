<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskView;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskViewsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    private Task $first;

    private Task $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->lookAt($this->alpha['admin']);
        $this->actingAs($this->alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $this->first = $this->createTask('Install north door', '2026-09-10', $group->id, $status->id);
        $this->second = $this->createTask('Measure south door', '2026-09-20', $group->id, $status->id);
    }

    public function test_board_rows_validate_and_apply_server_owned_query_with_complete_summaries_and_etag(): void
    {
        $activeStatus = $this->board->statusOptions()->active()->where('category', StatusCategory::Active)->firstOrFail();
        $this->patchJson("/api/v1/tasks/{$this->second->id}", [
            'expected_version' => $this->second->version, 'status_option_id' => $activeStatus->id,
        ])->assertOk();
        $this->enterTenant();
        $this->second = $this->second->fresh();
        $configuration = $this->configuration([
            'filters' => [['field' => 'due_on', 'operator' => 'between', 'value' => ['2026-09-01', '2026-09-30']]],
            'sorts' => [['field' => 'due_on', 'direction' => 'desc']],
            'hidden_columns' => ['task', 'owner'],
            'column_order' => ['task', 'due_on', 'status'],
            'column_widths' => ['task' => 320],
            'group_by' => 'status',
            'search' => 'door',
        ]);
        $url = "/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration), 'per_page' => 1,
        ]);
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.id', $this->second->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.configuration.group_by', 'status')
            ->assertJsonPath('meta.configuration.hidden_columns', ['owner'])
            ->assertJsonPath('meta.configuration.column_order', ['due_on', 'status'])
            ->assertJsonMissingPath('meta.configuration.column_widths.task');
        $this->assertSame(2, array_sum(array_column($response->json('meta.status_summaries'), 'count')));
        $this->assertCount(2, $response->json('meta.group_counts'));
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertNoContent(304);

        $configuration['sorts'] = [['field' => 'custom:not-a-uuid', 'direction' => 'asc']];
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertUnprocessable()->assertJsonValidationErrors('configuration.sorts.0.field');

        $configuration = $this->configuration([
            'filters' => [['field' => 'due_on', 'operator' => 'on', 'value' => '2026-02-30']],
        ]);
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertUnprocessable()->assertJsonValidationErrors('configuration.filters.0.value');

        $this->enterTenant();
        $this->first = $this->first->fresh();
        $this->patchJson("/api/v1/tasks/{$this->first->id}", [
            'expected_version' => $this->first->version,
            'owner_user_id' => $this->alpha['admin']->id,
        ])->assertOk();
        $this->enterTenant();
        $this->first = $this->first->fresh();
        $configuration = $this->configuration(['filters' => [[
            'field' => 'owner', 'operator' => 'contains_any', 'value' => [$this->alpha['admin']->id],
        ]]]);
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->first->id);
        $configuration = $this->configuration(['filters' => [
            'field' => 'owner', 'operator' => 'contains_any', 'value' => [$this->alpha['admin']->id],
        ]]);
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertUnprocessable()->assertJsonValidationErrors('configuration.filters');
        $configuration = $this->configuration(['filters' => [[
            'field' => 'owner', 'operator' => 'contains_any', 'value' => [(string) Str::uuid7()],
        ]]]);
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertUnprocessable()->assertJsonValidationErrors('configuration.filters.0.value');

        $columnId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => 'Tags', 'type' => 'label',
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();
        $excluded = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$columnId}/options", [
            'name' => 'Excluded', 'expected_structure_version' => $this->board->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();
        $kept = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$columnId}/options", [
            'name' => 'Kept', 'expected_structure_version' => $this->board->structure_version,
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/tasks/{$this->first->id}/columns/{$columnId}", [
            'expected_version' => $this->first->version, 'value' => [$excluded, $kept],
        ])->assertOk();
        $this->putJson("/api/v1/tasks/{$this->second->id}/columns/{$columnId}", [
            'expected_version' => $this->second->version, 'value' => [$kept],
        ])->assertOk();
        $configuration = $this->configuration(['filters' => [[
            'field' => 'custom:'.$columnId, 'operator' => 'not_in', 'value' => [$excluded],
        ]]]);
        $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->second->id);
    }

    public function test_personal_shared_and_locked_view_lifecycle_is_versioned_and_preferences_stay_personal(): void
    {
        $personalId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Mine', 'visibility' => 'personal', 'configuration' => $this->configuration(['search' => 'north']),
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/views/{$personalId}", [
            'expected_view_version' => 1, 'name' => 'Mine updated',
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/views/{$personalId}", [
            'expected_view_version' => 1, 'name' => 'Stale',
        ])->assertConflict()->assertJsonPath('meta.current_view_version', 2);

        $structureVersion = $this->board->fresh()->structure_version;
        $sharedResponse = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Locked dispatch', 'visibility' => 'shared', 'is_locked' => true,
            'expected_structure_version' => $structureVersion,
            'configuration' => $this->configuration(),
        ])->assertCreated()->assertJsonPath('meta.structure_version', $structureVersion + 1);
        $sharedId = $sharedResponse->json('data.id');
        $editor = $this->userWithRole($this->alpha, 'Editor', ['tasks.view', 'tasks.edit']);
        $this->lookAt($editor);
        $this->signedInAs($editor);
        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/views/{$sharedId}", [
            'expected_view_version' => 1, 'name' => 'No',
        ])->assertForbidden();
        $editorPersonalId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Editor private', 'visibility' => 'personal', 'configuration' => $this->configuration(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/views/{$editorPersonalId}", [
            'expected_view_version' => 1, 'expected_structure_version' => $structureVersion + 1,
            'visibility' => 'shared', 'is_locked' => true,
        ])->assertForbidden();
        $copyId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views/{$sharedId}/duplicate", [
            'name' => 'My dispatch', 'expected_view_version' => 1,
        ])->assertCreated()->assertJsonPath('data.visibility', 'personal')
            ->assertJsonPath('data.owner_user_id', $editor->id)->json('data.id');
        $this->enterTenant();
        $groupId = $this->board->groups()->active()->firstOrFail()->id;
        $this->putJson("/api/v1/tasks/boards/{$this->board->id}/views/{$copyId}/preference", [
            'collapsed_group_ids' => [$groupId], 'density' => 'comfortable',
            'is_last_active' => true, 'scroll_left' => 30, 'scroll_top' => 40,
        ])->assertOk()->assertJsonPath('data.density', 'comfortable');

        $this->enterTenant();
        $this->assertSame(2, TaskView::where('owner_user_id', $editor->id)->count());
        $this->signedInAs($this->alpha['admin']);
        $this->lookAt($this->alpha['admin']);
        $this->deleteJson("/api/v1/tasks/boards/{$this->board->id}/views/".$this->board->views()->where('is_permanent_main', true)->value('id'))
            ->assertUnprocessable();
    }

    public function test_view_actions_and_creation_capabilities_are_authored_by_the_server(): void
    {
        $personalId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Mine', 'visibility' => 'personal', 'configuration' => $this->configuration(),
        ])->assertCreated()->json('data.id');
        $structureVersion = $this->board->fresh()->structure_version;
        $unlockedId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Team dispatch', 'visibility' => 'shared', 'is_locked' => false,
            'expected_structure_version' => $structureVersion, 'configuration' => $this->configuration(),
        ])->assertCreated()->json('data.id');
        $lockedId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Locked dispatch', 'visibility' => 'shared', 'is_locked' => true,
            'expected_structure_version' => $structureVersion + 1, 'configuration' => $this->configuration(),
        ])->assertCreated()->json('data.id');

        $owner = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/views")->assertOk()
            ->assertJsonPath('meta.capabilities.create_personal', true)
            ->assertJsonPath('meta.capabilities.create_shared', true);
        $ownerUnlocked = collect($owner->json('data'))->firstWhere('id', $unlockedId);
        $this->assertSame(['update', 'duplicate', 'delete'], $ownerUnlocked['actions']);
        $ownerPersonal = collect($owner->json('data'))->firstWhere('id', $personalId);
        $this->assertSame(['update', 'delete'], $ownerPersonal['actions']);

        $editor = $this->userWithRole($this->alpha, 'View editor', ['tasks.view', 'tasks.edit']);
        $this->lookAt($editor);
        $this->signedInAs($editor);
        $editorResponse = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/views")->assertOk()
            ->assertJsonPath('meta.capabilities.create_personal', true)
            ->assertJsonPath('meta.capabilities.create_shared', false);
        $views = collect($editorResponse->json('data'))->keyBy('id');
        $this->assertSame(['update', 'duplicate'], $views[$unlockedId]['actions']);
        $this->assertSame(['duplicate'], $views[$lockedId]['actions']);
    }

    private function createTask(string $title, string $dueOn, string $groupId, string $statusId): Task
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'due_on' => $dueOn, 'group_id' => $groupId, 'status_option_id' => $statusId,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function configuration(array $replace = []): array
    {
        return array_replace([
            'schema' => 1, 'presentation' => 'table', 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => ['task', 'owner', 'status', 'priority', 'start_on', 'due_on'],
            'column_widths' => (object) [], 'group_by' => null, 'search' => '',
        ], $replace);
    }

    private function lookAt($user): void
    {
        $this->enterTenant();
        $user->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id,
        ]])->save();
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
