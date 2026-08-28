<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskDependency;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskDependenciesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Operations', 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
    }

    public function test_direct_cycle_is_refused(): void
    {
        $a = $this->createTask('A');
        $b = $this->createTask('B');
        // A blocks B.
        $this->addDependency($a, $b)->assertCreated();

        // B blocks A would close the loop A -> B -> A.
        $response = $this->addDependency($b, $a)->assertForbidden();
        $this->assertStringContainsString('wait on the other', (string) $response->json('message'));

        $this->enterTenant();
        $this->assertSame(1, TaskDependency::count());
    }

    public function test_indirect_cycle_is_refused(): void
    {
        $a = $this->createTask('A');
        $b = $this->createTask('B');
        $c = $this->createTask('C');
        // A blocks B, B blocks C.
        $this->addDependency($a, $b)->assertCreated();
        $this->addDependency($b, $c)->assertCreated();

        // C blocks A would close the loop A -> B -> C -> A.
        $response = $this->addDependency($c, $a)->assertForbidden();
        $this->assertStringContainsString('wait on the other', (string) $response->json('message'));

        $this->enterTenant();
        $this->assertSame(2, TaskDependency::count());
    }

    public function test_cross_board_pair_is_refused(): void
    {
        $a = $this->createTask('A');
        $otherBoardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Other', 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $otherBoard = TaskBoard::findOrFail($otherBoardId);
        $d = $this->createTaskOn($otherBoard, 'D');

        $response = $this->postJson("/api/v1/tasks/{$a->id}/dependencies", ['blocker_task_id' => $d->id])
            ->assertForbidden();
        $this->assertStringContainsString('same board', (string) $response->json('message'));

        $this->enterTenant();
        $this->assertSame(0, TaskDependency::count());
    }

    public function test_duplicate_edge_is_refused(): void
    {
        $a = $this->createTask('A');
        $b = $this->createTask('B');
        $this->addDependency($a, $b)->assertCreated();

        $this->addDependency($a, $b)->assertUnprocessable();

        $this->enterTenant();
        $this->assertSame(1, TaskDependency::count());
    }

    public function test_invisible_task_is_refused_without_disclosing_existence(): void
    {
        // A private board the admin creates and stays the only member of, plus a
        // second, unrelated user with full organization-wide reach but no
        // membership on it — a task on it is invisible to that second user even
        // though nothing about their permissions or assignment is restricted.
        $a = $this->createTask('A');
        $privateBoardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Confidential', 'visibility' => 'private'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $privateBoard = TaskBoard::findOrFail($privateBoardId);
        $hidden = $this->createTaskOn($privateBoard, 'Hidden');

        $stranger = $this->userWithRole($this->tenant, 'Stranger', ['tasks.edit', 'tasks.view']);
        $this->signedInAs($stranger);

        $realButHidden = $this->postJson("/api/v1/tasks/{$a->id}/dependencies", ['blocker_task_id' => $hidden->id]);
        $madeUp = $this->postJson("/api/v1/tasks/{$a->id}/dependencies", ['blocker_task_id' => (string) Str::uuid()]);

        $realButHidden->assertNotFound();
        $madeUp->assertNotFound();
        $this->assertSame($madeUp->status(), $realButHidden->status());
        // Both say "no query results for this id" — the id came from the
        // caller's own request, so echoing it back discloses nothing; what
        // must not differ is whether one path 403s while the other 404s.
        $this->assertStringContainsString('No query results for model', (string) $realButHidden->json('message'));
        $this->assertStringContainsString('No query results for model', (string) $madeUp->json('message'));

        $this->enterTenant();
        $this->assertSame(0, TaskDependency::count());
    }

    public function test_add_and_remove_each_write_an_audit_row_and_an_outbox_event(): void
    {
        $a = $this->createTask('A');
        $b = $this->createTask('B');
        $dependencyId = $this->addDependency($a, $b)->assertCreated()->json('data.id');

        $this->enterTenant();
        $this->assertDatabaseHas('audit_events', ['action' => 'task.dependency_added', 'target_id' => $dependencyId]);
        $this->assertTrue(AutomationEvent::where('event_key', 'task.dependency_added')
            ->where('subject_id', $dependencyId)->exists());

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->deleteJson("/api/v1/tasks/dependencies/{$dependencyId}")->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->enterTenant();
        $this->assertDatabaseHas('audit_events', ['action' => 'task.dependency_removed', 'target_id' => $dependencyId]);
        $this->assertTrue(AutomationEvent::where('event_key', 'task.dependency_removed')
            ->where('subject_id', $dependencyId)->exists());
        $this->assertSame(0, TaskDependency::count());
    }

    public function test_deleting_either_task_removes_the_edge(): void
    {
        // Built directly against the model rather than through the create API:
        // a real task always carries a `task.created` activity row restricted
        // against it, so a hard delete never happens in the product. The
        // compound foreign keys on task_dependencies are schema, not writer
        // behaviour — this proves the cascade the migration declared, with
        // nothing else attached to get in its way.
        $this->enterTenant();
        $a = $this->bareTask('A');
        $b = $this->bareTask('B');
        TaskDependency::create([
            'board_id' => $this->board->id, 'blocker_task_id' => $a->id,
            'blocked_task_id' => $b->id, 'created_by' => $this->tenant['admin']->id,
        ]);
        $this->assertSame(1, TaskDependency::count());
        $a->delete();
        $this->assertSame(0, TaskDependency::count());

        $c = $this->bareTask('C');
        $d = $this->bareTask('D');
        TaskDependency::create([
            'board_id' => $this->board->id, 'blocker_task_id' => $c->id,
            'blocked_task_id' => $d->id, 'created_by' => $this->tenant['admin']->id,
        ]);
        $this->assertSame(1, TaskDependency::count());
        $d->delete();
        $this->assertSame(0, TaskDependency::count());
    }

    public function test_completing_a_blocked_task_succeeds(): void
    {
        $a = $this->createTask('A');
        $b = $this->createTask('B');
        $this->addDependency($a, $b)->assertCreated();

        $this->enterTenant();
        $completed = $this->board->statusOptions()->active()->where('category', StatusCategory::Completed)->firstOrFail();
        $this->patchJson("/api/v1/tasks/{$b->id}", [
            'expected_version' => $b->fresh()->version,
            'status_option_id' => $completed->id,
        ])->assertOk();

        $this->enterTenant();
        $this->assertNotNull($b->fresh()->completed_at);
    }

    public function test_blocked_by_appears_on_task_detail_and_timeline_rows_only(): void
    {
        $a = $this->createTask('A', '2026-09-01', '2026-09-05');
        $b = $this->createTask('B', '2026-09-01', '2026-09-05');
        $this->addDependency($a, $b)->assertCreated();

        $detail = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/tasks/{$b->id}")->assertOk();
        $blockedBy = $detail->json('data.blocked_by');
        $this->assertCount(1, $blockedBy);
        $this->assertSame($a->id, $blockedBy[0]['task_id']);
        $this->assertSame($a->number, $blockedBy[0]['number']);
        $this->assertSame('A', $blockedBy[0]['title']);

        $configuration = $this->configuration('timeline', ['from' => '2026-08-01', 'to' => '2026-10-01']);
        $rows = $this->getJson($this->rowsUrl($configuration))->assertOk();
        $row = collect($rows->json('data'))->firstWhere('id', $b->id);
        $this->assertNotNull($row);
        $this->assertCount(1, $row['blocked_by']);
        $this->assertSame($a->id, $row['blocked_by'][0]['task_id']);

        $this->enterTenant();
        $kanbanConfiguration = $this->configuration('kanban', [
            'bucket_field' => 'status',
            'buckets' => [$this->board->statusOptions()->active()->pluck('id')->first()],
        ]);
        $kanbanRows = $this->getJson($this->rowsUrl($kanbanConfiguration))->assertOk();
        $kanbanRow = collect($kanbanRows->json('data'))->first();
        if ($kanbanRow !== null) {
            $this->assertArrayNotHasKey('blocked_by', $kanbanRow);
        }
    }

    private function addDependency(Task $blocker, Task $blocked)
    {
        return $this->postJson("/api/v1/tasks/{$blocked->id}/dependencies", ['blocker_task_id' => $blocker->id]);
    }

    private function createTask(string $title, ?string $startOn = null, ?string $dueOn = null): Task
    {
        return $this->createTaskOn($this->board, $title, $startOn, $dueOn);
    }

    private function createTaskOn(TaskBoard $board, string $title, ?string $startOn = null, ?string $dueOn = null): Task
    {
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
            'start_on' => $startOn, 'due_on' => $dueOn,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function bareTask(string $title): Task
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $position = (int) Task::query()->where('board_id', $this->board->id)->where('group_id', $group->id)
            ->max('position') + 1;
        $task = new Task;
        $task->forceFill([
            'board_id' => $this->board->id, 'group_id' => $group->id,
            'number' => 'DEL-'.Str::random(12), 'title' => $title,
            'status_option_id' => $status->id, 'position' => $position,
            'created_by' => $this->tenant['admin']->id, 'updated_by' => $this->tenant['admin']->id,
        ])->save();

        return $task;
    }

    private function configuration(string $presentation, array $window): array
    {
        return [
            'schema' => 2, 'presentation' => $presentation, 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => [], 'column_widths' => (object) [],
            'group_by' => null, 'search' => '', 'window' => $window,
        ];
    }

    private function rowsUrl(array $configuration, array $extra = []): string
    {
        return "/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration), ...$extra,
        ]);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
