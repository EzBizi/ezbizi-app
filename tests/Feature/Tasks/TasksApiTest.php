<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskNotificationIntent;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TasksApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

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
        $id = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($id);
    }

    public function test_create_sanitizes_description_validates_participants_and_writes_atomic_records(): void
    {
        $collaborator = $this->userWithRole($this->alpha, 'Collaborator', ['tasks.view', 'tasks.edit']);
        $this->enterTenant();
        $group = $this->group();
        $status = $this->taskStatus(StatusCategory::NotStarted);

        $response = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Inspect site',
            'description' => '<p style="color:red" onclick="bad()">Hello <strong>team</strong><script>alert(1)</script> <a href="http://bad.test">bad</a> <a href="https://safe.test">safe</a><img src="data:x"> </p>',
            'group_id' => $group->id,
            'status_option_id' => $status->id,
            'owner_user_id' => $collaborator->id,
            'collaborator_user_ids' => [$collaborator->id],
            'due_on' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Inspect site')
            ->assertJsonPath('data.owner_user_id', $collaborator->id)
            ->assertJsonPath('data.due_revision', 1)
            ->assertJsonPath('data.version', 1);

        $html = (string) $response->json('data.description');
        $this->assertStringContainsString('<strong>team</strong>', $html);
        $this->assertStringContainsString('https://safe.test', $html);
        $this->assertStringNotContainsString('http://bad.test', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('Hello team', (string) $response->json('data.description_plain'));

        $this->enterTenant();
        $task = Task::findOrFail($response->json('data.id'));
        $this->assertSame(1, TaskActivity::where('task_id', $task->id)->count());
        $this->assertSame(3, AutomationEvent::where('subject_id', $task->id)->count());
        $this->assertSame(2, TaskNotificationIntent::where('task_id', $task->id)->count());
        $this->assertSame(2, $group->fresh()->order_version);
        $this->assertDatabaseHas('audit_events', ['action' => 'task.created', 'target_id' => $task->id]);

        $task = $this->patchTask($task, ['collaborator_user_ids' => []]);
        $audit = AuditEvent::where('action', 'task.updated')->where('target_id', $task->id)->latest('occurred_at')->firstOrFail();
        $this->assertSame([$collaborator->id], $audit->changes['before']['collaborator_user_ids']);
        $this->assertSame([], $audit->changes['after']['collaborator_user_ids']);
        $activity = TaskActivity::where('task_id', $task->id)->where('kind', 'task.updated')->firstOrFail();
        $this->assertSame([$collaborator->id], $activity->data['changes']['collaborator_user_ids']['before']);
        $this->assertSame([], $activity->data['changes']['collaborator_user_ids']['after']);
    }

    public function test_due_revisions_and_stable_status_categories_drive_resolution_without_touching_subtasks(): void
    {
        $parent = $this->createTask('Parent', dueOn: '2026-09-01');
        $child = $this->createTask('Child', parentId: $parent->id);
        $completed = $this->taskStatus(StatusCategory::Completed);
        $active = $this->taskStatus(StatusCategory::Active);
        $cancelled = $this->taskStatus(StatusCategory::Cancelled);

        $parent = $this->patchTask($parent, ['due_on' => '2026-09-02']);
        $this->assertSame(2, $parent->due_revision);
        $parent = $this->patchTask($parent, ['due_on' => '2026-09-01']);
        $this->assertSame(3, $parent->due_revision);
        $parent = $this->patchTask($parent, ['owner_user_id' => $this->alpha['admin']->id]);
        $this->assertSame(3, $parent->due_revision);

        $parent = $this->patchTask($parent, ['status_option_id' => $completed->id]);
        $this->assertNotNull($parent->completed_at);
        $this->assertSame($this->alpha['admin']->id, $parent->completed_by);
        $this->assertSame($child->status_option_id, $child->fresh()->status_option_id);

        $parent = $this->patchTask($parent, ['status_option_id' => $active->id]);
        $this->assertNull($parent->completed_at);
        $this->enterTenant();
        $this->assertTrue(AutomationEvent::where('subject_id', $parent->id)
            ->where('event_key', 'task.reopened')->exists());

        $parent = $this->patchTask($parent, ['status_option_id' => $cancelled->id]);
        $this->assertNotNull($parent->cancelled_at);
        $this->assertNull($parent->completed_at);
    }

    public function test_one_level_subtasks_and_parent_archive_restore_are_consistent(): void
    {
        $parent = $this->createTask('Parent');
        $child = $this->createTask('Child', parentId: $parent->id);
        $historyChild = $this->createTask('Archived child title', parentId: $parent->id);
        $this->postJson("/api/v1/tasks/{$historyChild->id}/archive", [
            'expected_version' => $historyChild->version,
        ])->assertOk();
        $response = $this->patchJson("/api/v1/tasks/{$parent->id}", [
            'expected_version' => $parent->version, 'title' => 'Parent changed',
        ])->assertOk();
        $this->assertNotContains($historyChild->id, collect($response->json('data.subtasks'))->pluck('id')->all());
        $this->assertStringNotContainsString('Archived child title', $response->getContent());
        $this->enterTenant();
        $parent = $parent->fresh();

        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Too deep',
            'parent_task_id' => $child->id,
            'status_option_id' => $this->taskStatus(StatusCategory::NotStarted)->id,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/tasks/{$parent->id}/archive", [
            'expected_version' => $parent->version,
        ])->assertOk()->assertJsonPath('data.archived', true);
        $this->enterTenant();
        $parent = $parent->fresh();
        $child = $child->fresh();
        $this->assertNotNull($child->archived_at);
        $this->assertSame($parent->id, $child->archived_with_parent_id);

        $this->postJson("/api/v1/tasks/{$child->id}/restore", [
            'expected_version' => $child->version,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/tasks/{$parent->id}/restore", [
            'expected_version' => $parent->version,
        ])->assertOk()->assertJsonPath('data.archived', false);
        $this->enterTenant();
        $this->assertNull($child->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_with_parent_id);
    }

    public function test_bulk_refuses_every_change_when_one_target_is_stale_then_updates_each_task_atomically(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $staleFirstVersion = $first->version;
        $first = $this->patchTask($first, ['title' => 'First changed']);

        $payload = [
            'tasks' => [
                ['id' => $first->id, 'expected_version' => $staleFirstVersion],
                ['id' => $second->id, 'expected_version' => $second->version],
            ],
            'changes' => ['due_on' => '2026-10-10'],
        ];
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $payload)
            ->assertUnprocessable();
        $this->enterTenant();
        $this->assertNull($first->fresh()->due_on);
        $this->assertNull($second->fresh()->due_on);

        $payload['tasks'][0]['expected_version'] = $first->version;
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $payload)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $this->assertSame('2026-10-10', $first->fresh()->due_on?->format('Y-m-d'));
        $this->assertSame('2026-10-10', $second->fresh()->due_on?->format('Y-m-d'));
        $causations = DB::table('audit_events')->whereIn('target_id', [$first->id, $second->id])
            ->where('action', 'task.updated')->whereNotNull('causation_id')->pluck('causation_id')->unique();
        $this->assertCount(1, $causations);

        $parent = $this->createTask('Bulk parent');
        $child = $this->createTask('Bulk child', parentId: $parent->id);
        $cascade = [
            'tasks' => [
                ['id' => $parent->id, 'expected_version' => $parent->version],
                ['id' => $child->id, 'expected_version' => $child->version],
            ],
            'changes' => ['archived' => true],
        ];
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $cascade)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $parent = $parent->fresh();
        $child = $child->fresh();
        $this->assertNotNull($parent->archived_at);
        $this->assertSame($parent->id, $child->archived_with_parent_id);

        $cascade['tasks'][0]['expected_version'] = $parent->version;
        $cascade['tasks'][1]['expected_version'] = $child->version;
        $cascade['changes']['archived'] = false;
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/bulk", $cascade)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->enterTenant();
        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_at);
    }

    private function createTask(string $title, ?string $parentId = null, ?string $dueOn = null): Task
    {
        $payload = [
            'title' => $title,
            'status_option_id' => $this->taskStatus(StatusCategory::NotStarted)->id,
        ];
        if ($parentId === null) {
            $payload['group_id'] = $this->group()->id;
        } else {
            $payload['parent_task_id'] = $parentId;
        }
        if ($dueOn !== null) {
            $payload['due_on'] = $dueOn;
        }
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", $payload)
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function patchTask(Task $task, array $changes): Task
    {
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            ...$changes,
        ])->assertOk();
        $this->enterTenant();

        return $task->fresh();
    }

    private function group()
    {
        return $this->board->groups()->active()->where('name', 'To do')->firstOrFail();
    }

    private function taskStatus(StatusCategory $category)
    {
        return $this->board->statusOptions()->active()->where('category', $category)->firstOrFail();
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
