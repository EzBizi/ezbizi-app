<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskNotificationIntent;
use App\Models\TaskUpdate;
use App\Support\Automations\AutomationEngine;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\TasksGlance;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskNotificationIntentDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskWorkflowTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00 UTC');
        Queue::fake();
        $this->tenant = $this->provisionTenant('Task Workflow');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => [
                'navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id,
            ],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
    }

    public function test_company_board_workflow_reaches_glance_delivery_automation_and_archive(): void
    {
        $assignee = $this->userWithRole(
            $this->tenant,
            'Task Assignee',
            ['tasks.view', 'tasks.edit', 'tasks.comment'],
        );
        $this->enterTenant();
        $assignee->forceFill([
            'preferences' => [
                'navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id,
            ],
        ])->save();

        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'August Operations',
            'visibility' => 'scope',
        ])->assertCreated()->json('data.id');

        $this->enterTenant();
        $board = TaskBoard::findOrFail($boardId);
        $group = $board->groups()->active()->where('name', 'To do')->firstOrFail();
        $status = $board->statusOptions()->active()
            ->where('category', StatusCategory::NotStarted)->firstOrFail();
        $completed = $board->statusOptions()->active()
            ->where('category', StatusCategory::Completed)->firstOrFail();
        $mainViewId = $board->views()->where('is_permanent_main', true)->value('id');

        $columnId = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => 'Crew note',
            'type' => 'text',
            'expected_structure_version' => 1,
        ])->assertCreated()->assertJsonPath('meta.structure_version', 2)->json('data.id');

        $taskId = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Inspect chimney',
            'description' => '<p>Photograph the crown.</p>',
            'group_id' => $group->id,
            'status_option_id' => $status->id,
            'owner_user_id' => $assignee->id,
        ])->assertCreated()->json('data.id');

        $this->enterTenant();
        $task = Task::findOrFail($taskId);
        $this->putJson("/api/v1/tasks/{$task->id}/columns/{$columnId}", [
            'expected_version' => $task->version,
            'value' => 'Bring the inspection camera',
        ])->assertOk()->assertJsonPath('data.value', 'Bring the inspection camera');

        $this->enterTenant();
        $customer = Customer::factory()->create([
            'owning_franchise_id' => $this->tenant['franchise']->id,
            'number' => 'C-WORKFLOW',
            'name' => 'Workflow Customer',
        ]);
        $task = Task::findOrFail($taskId);
        $this->postJson("/api/v1/tasks/{$task->id}/record-links", [
            'expected_task_version' => $task->version,
            'record_type' => 'customer',
            'record_id' => $customer->id,
        ])->assertCreated()->assertJsonPath('data.label', 'Workflow Customer');

        $this->postJson("/api/v1/tasks/{$task->id}/updates", [
            'body' => '<p>Customer confirmed access.</p>',
            'mention_user_ids' => [$assignee->id],
            'idempotency_key' => 'workflow-confirmation',
        ])->assertCreated();

        $configuration = $this->configuration(['search' => 'Inspect chimney']);
        $viewId = $this->postJson("/api/v1/tasks/boards/{$board->id}/views", [
            'name' => 'Inspection queue',
            'visibility' => 'personal',
            'configuration' => $configuration,
        ])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/tasks/boards/{$board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $taskId);
        $csv = $this->get("/api/v1/tasks/boards/{$board->id}/exports/csv?active_view_id={$viewId}")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('Inspect chimney', $csv->streamedContent());

        $this->enterTenant();
        $sourceEvent = AutomationEvent::where('event_key', 'task.created')
            ->where('subject_id', $taskId)->firstOrFail();
        $automationId = $this->postJson('/api/v1/automations', [
            'name' => 'Schedule inspections',
            'trigger_key' => 'task.created',
            'scope_type' => 'company',
            'scope_id' => $this->tenant['company']->id,
            'actions' => [[
                'type' => 'task.set_due_date',
                'due_on' => '2026-09-02',
            ]],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/automations/{$automationId}", ['is_active' => true])->assertOk();
        $this->enterTenant();
        $this->assertSame(1, app(AutomationEngine::class)->deliver($sourceEvent));

        $task = Task::findOrFail($taskId);
        $this->assertSame('2026-09-02', $task->due_on?->format('Y-m-d'));
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            'status_option_id' => $completed->id,
        ])->assertOk();
        $this->enterTenant();
        $task = Task::findOrFail($taskId);
        $this->assertNotNull($task->completed_at);

        $glance = app(TasksGlance::class)->resolve(
            $this->tenant['admin']->fresh(),
            DashboardPeriod::for($this->tenant['admin']->fresh(), 'month'),
            [],
        )->toArray();
        $this->assertSame(1, $glance['data']['counts']['completed_in_period']);

        $this->assertGreaterThanOrEqual(1, app(TaskNotificationIntentDispatcher::class)->dispatchPending());
        $this->signedInAs($assignee->fresh());
        $notifications = $this->getJson('/api/v1/notifications')->assertOk();
        $notificationId = $notifications->json('data.0.id');
        $this->assertNotNull($notificationId);
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertOk();
        $this->enterTenant();
        $this->assertNotNull(Notification::findOrFail($notificationId)->read_at);

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->enterTenant();
        $task = Task::findOrFail($taskId);
        $this->assertTrue(AuditEvent::where('target_id', $taskId)
            ->where('action', 'task.updated')->exists());
        $this->assertTrue(TaskActivity::where('task_id', $taskId)
            ->where('kind', 'task.update_added')->exists());
        $this->assertTrue(AutomationEvent::where('subject_id', $taskId)
            ->where('event_key', 'task.completed')->exists());
        $eventPayload = AutomationEvent::where('subject_id', $taskId)
            ->where('event_key', 'task.created')->firstOrFail()->payload;
        $this->assertArrayNotHasKey('title', $eventPayload);
        $this->assertArrayNotHasKey('description', $eventPayload);
        $intent = TaskNotificationIntent::where('task_id', $taskId)->firstOrFail();
        $this->assertArrayNotHasKey('title', $intent->getAttributes());
        $this->assertArrayNotHasKey('body', $intent->getAttributes());

        $this->postJson("/api/v1/tasks/{$task->id}/archive", [
            'expected_version' => $task->version,
        ])->assertOk()->assertJsonPath('data.archived', true);

        $manifest = $this->get(
            "/api/v1/tasks/boards/{$board->id}/exports/json?active_view_id={$mainViewId}&include_archived=1",
        )->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $decoded = json_decode($manifest->streamedContent(), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame('ezbizi.tasks.archive-manifest.v1', $decoded['format']);
        $this->assertSame('Inspect chimney', $decoded['tasks'][0]['title']);
        $this->assertTrue($decoded['tasks'][0]['archived']);
        $this->assertSame('Customer confirmed access.', $decoded['tasks'][0]['updates'][0]['body_plain']);
        $this->assertSame('Workflow Customer', $decoded['tasks'][0]['record_links'][0]['label']);

        $this->enterTenant();
        $board = TaskBoard::findOrFail($boardId);
        $this->postJson("/api/v1/tasks/boards/{$board->id}/archive", [
            'expected_structure_version' => $board->structure_version,
        ])->assertOk()->assertJsonPath('data.archived', true);
    }

    public function test_successful_configuration_mutations_advance_one_authoritative_version_and_emit_exact_audits(): void
    {
        $this->enterTenant();
        $auditBefore = DB::table('audit_events')->pluck('id')->all();
        $folderId = $this->postJson('/api/v1/tasks/folders', ['name' => 'Configuration'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/tasks/folders/{$folderId}", ['name' => 'Configuration updated'])
            ->assertOk()->assertJsonPath('data.name', 'Configuration updated');
        $this->postJson("/api/v1/tasks/folders/{$folderId}/archive")
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson("/api/v1/tasks/folders/{$folderId}/restore")
            ->assertOk()->assertJsonPath('data.archived', false);

        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Configuration matrix', 'visibility' => 'scope', 'folder_id' => $folderId,
        ])->assertCreated()->assertJsonPath('data.structure_version', 1)->json('data.id');
        $this->enterTenant();
        $board = TaskBoard::findOrFail($boardId);
        $version = 1;
        $advance = function ($response) use (&$version): void {
            $response->assertSuccessful()->assertJsonPath('meta.structure_version', ++$version);
        };

        $group = $this->postJson("/api/v1/tasks/boards/{$board->id}/groups", [
            'name' => 'Configuration group', 'expected_structure_version' => $version,
        ]);
        $advance($group);
        $groupId = $group->json('data.id');
        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/groups/{$groupId}", [
            'name' => 'Configuration group updated', 'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/groups/{$groupId}/archive", [
            'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/groups/{$groupId}/restore", [
            'expected_structure_version' => $version,
        ]));
        $this->enterTenant();
        $groupIds = $board->groups()->active()->orderByDesc('position')->pluck('id')->all();
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/groups/reorder", [
            'ordered_ids' => $groupIds, 'expected_structure_version' => $version,
        ]));

        $status = $this->postJson("/api/v1/tasks/boards/{$board->id}/statuses", [
            'name' => 'Configuration status', 'category' => 'active',
            'expected_structure_version' => $version,
        ]);
        $advance($status);
        $statusId = $status->json('data.id');
        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/statuses/{$statusId}", [
            'name' => 'Configuration status updated', 'color' => '#1769AA',
            'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/statuses/{$statusId}/archive", [
            'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/statuses/{$statusId}/restore", [
            'expected_structure_version' => $version,
        ]));
        $this->enterTenant();
        $statusIds = $board->statusOptions()->active()->orderByDesc('position')->pluck('id')->all();
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/statuses/reorder", [
            'ordered_ids' => $statusIds, 'expected_structure_version' => $version,
        ]));

        $this->enterTenant();
        $priority = $board->priorityOptions()->active()->where('rank', 'low')->firstOrFail();
        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/priorities/{$priority->id}", [
            'name' => 'Someday', 'color' => '#777777', 'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/priorities/{$priority->id}/archive", [
            'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/priorities/{$priority->id}/restore", [
            'expected_structure_version' => $version,
        ]));
        $this->enterTenant();
        $priorityIds = $board->priorityOptions()->active()->orderByDesc('position')->pluck('id')->all();
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/priorities/reorder", [
            'ordered_ids' => $priorityIds, 'expected_structure_version' => $version,
        ]));

        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/columns/core/due_on", [
            'display_label' => 'Deadline', 'expected_structure_version' => $version,
        ]));
        $column = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => 'Workstream', 'type' => 'label', 'expected_structure_version' => $version,
        ]);
        $advance($column);
        $columnId = $column->json('data.id');
        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}", [
            'name' => 'Workstream updated', 'expected_structure_version' => $version,
        ]));
        $firstOption = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options", [
            'name' => 'Installation', 'expected_structure_version' => $version,
        ]);
        $advance($firstOption);
        $firstOptionId = $firstOption->json('data.id');
        $secondOption = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options", [
            'name' => 'Repair', 'expected_structure_version' => $version,
        ]);
        $advance($secondOption);
        $secondOptionId = $secondOption->json('data.id');
        $advance($this->patchJson(
            "/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options/{$firstOptionId}",
            ['name' => 'New installation', 'expected_structure_version' => $version],
        ));
        $advance($this->postJson(
            "/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options/{$firstOptionId}/archive",
            ['expected_structure_version' => $version],
        ));
        $advance($this->postJson(
            "/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options/{$firstOptionId}/restore",
            ['expected_structure_version' => $version],
        ));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/options/reorder", [
            'ordered_ids' => [$firstOptionId, $secondOptionId], 'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/archive", [
            'expected_structure_version' => $version,
        ]));
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/columns/{$columnId}/restore", [
            'expected_structure_version' => $version,
        ]));
        $this->enterTenant();
        $columnIds = $board->columnDefinitions()->active()->orderByDesc('position')->pluck('id')->all();
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/columns/reorder", [
            'ordered_ids' => $columnIds, 'expected_structure_version' => $version,
        ]));

        $view = $this->postJson("/api/v1/tasks/boards/{$board->id}/views", [
            'name' => 'Configuration view', 'visibility' => 'shared',
            'configuration' => $this->configuration(), 'expected_structure_version' => $version,
        ]);
        $advance($view);
        $viewId = $view->json('data.id');
        $advance($this->patchJson("/api/v1/tasks/boards/{$board->id}/views/{$viewId}", [
            'name' => 'Configuration view updated', 'is_locked' => true,
            'expected_view_version' => 1, 'expected_structure_version' => $version,
        ]));
        $this->enterTenant();
        $viewIds = $board->views()->where('visibility', 'shared')->orderByDesc('position')->pluck('id')->all();
        $advance($this->postJson("/api/v1/tasks/boards/{$board->id}/views/reorder", [
            'visibility' => 'shared', 'view_ids' => $viewIds,
            'expected_structure_version' => $version,
        ]));
        $this->deleteJson("/api/v1/tasks/boards/{$board->id}/views/{$viewId}", [
            'expected_view_version' => 2, 'expected_structure_version' => $version,
        ])->assertNoContent();
        $version++;
        $this->enterTenant();
        $this->assertSame($version, (int) $board->fresh()->structure_version);

        $expectedActions = [
            'task_folder.created', 'task_folder.updated', 'task_folder.archived', 'task_folder.restored',
            'task_board.created',
            'task_group.created', 'task_group.updated', 'task_group.archived', 'task_group.restored', 'task_groups.reordered',
            'task_status.created', 'task_status.updated', 'task_status.archived', 'task_status.restored', 'task_statuses.reordered',
            'task_priority.updated', 'task_priority.archived', 'task_priority.restored', 'task_priorities.reordered',
            'task_core_column.updated', 'task_column.created', 'task_column.updated',
            'task_column_option.created', 'task_column_option.created', 'task_column_option.updated',
            'task_column_option.archived', 'task_column_option.restored', 'task_column_options.reordered',
            'task_column.archived', 'task_column.restored', 'task_columns.reordered',
            'task_view.created', 'task_view.updated', 'task_view.reordered', 'task_view.deleted',
        ];
        $actualActions = DB::table('audit_events')->whereNotIn('id', $auditBefore)->pluck('action')->all();
        $this->assertEqualsCanonicalizing($expectedActions, $actualActions);
    }

    private function configuration(array $replace = []): array
    {
        return array_replace([
            'schema' => 1,
            'presentation' => 'table',
            'filters' => [],
            'sorts' => [],
            'hidden_columns' => [],
            'column_order' => ['task', 'owner', 'status', 'priority', 'start_on', 'due_on'],
            'column_widths' => (object) [],
            'group_by' => null,
            'search' => '',
        ], $replace);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
