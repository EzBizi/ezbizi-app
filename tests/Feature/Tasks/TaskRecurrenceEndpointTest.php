<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `PUT tasks/{task}/recurrence` (task-13 brief) — the route
 * `TaskWriter::setRecurrence` needed and never had. `TaskRecurrenceDeclara-
 * tionTest` already covers the writer's own rules (subtask refusal,
 * versioned conflict, audit row) by calling it directly; this file proves
 * the HTTP edge around it, in particular that a board's exact `ViewScope`
 * is enforced here rather than left to `tasks.edit` alone.
 */
final class TaskRecurrenceEndpointTest extends TestCase
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

    /**
     * The whole reason this endpoint exists (task-13 brief): an actor who
     * holds `tasks.edit` organization-wide but cannot see this board's
     * exact `ViewScope` — no membership on a private board — must be
     * refused, not just the ones `TaskWriter::setRecurrence` itself checks
     * (permission + read-only org). Same non-enumerating 404 shape every
     * other `{task}`-bound route uses for an out-of-reach private board
     * (`TaskConcurrencyTest::test_private_task_is_not_enumerable_...`).
     */
    public function test_actor_outside_the_boards_exact_view_is_refused(): void
    {
        $privateId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Confidential', 'visibility' => 'private'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $private = TaskBoard::findOrFail($privateId);
        $task = $this->createTaskOn($private, 'Hidden');

        $stranger = $this->userWithRole($this->tenant, 'Stranger', ['tasks.edit', 'tasks.view']);
        $stranger->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($stranger);

        $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $task->version,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'week'],
        ])->assertNotFound();

        $this->enterTenant();
        $this->assertNull($task->fresh()->recurrence);
    }

    public function test_actor_who_can_see_the_board_and_holds_tasks_edit_succeeds(): void
    {
        $task = $this->createTask('Recurring inspection');

        $response = $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $task->version,
            'recurrence' => ['mode' => 'after_completion', 'every' => 2, 'unit' => 'week'],
        ])->assertOk();
        $this->assertSame($task->id, $response->json('data.id'));
        $this->assertSame((int) $task->version + 1, $response->json('data.version'));

        $this->enterTenant();
        $fresh = $task->fresh();
        $this->assertNotNull($fresh->recurrence);
        $this->assertSame('after_completion', $fresh->recurrence['mode']);
        $this->assertSame(2, $fresh->recurrence['every']);
        $this->assertSame('week', $fresh->recurrence['unit']);
    }

    public function test_a_stale_version_conflicts(): void
    {
        $task = $this->createTask('Recurring inspection');
        $staleVersion = (int) $task->version;

        $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $staleVersion,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'day'],
        ])->assertOk();

        $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $staleVersion,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 2, 'unit' => 'day'],
        ])->assertConflict()
            ->assertJsonPath('meta.current_version', $staleVersion + 1);

        $this->enterTenant();
        $this->assertSame(1, $task->fresh()->recurrence['every']);
    }

    public function test_a_subtask_is_refused(): void
    {
        $parent = $this->createTask('Parent');
        $subtask = $this->createSubtask($parent, 'Checklist item');

        $response = $this->putJson("/api/v1/tasks/{$subtask->id}/recurrence", [
            'expected_version' => $subtask->version,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'day'],
        ])->assertForbidden();
        $this->assertStringContainsString('cannot recur', (string) $response->json('message'));

        $this->enterTenant();
        $this->assertNull($subtask->fresh()->recurrence);
    }

    private function createTask(string $title): Task
    {
        return $this->createTaskOn($this->board, $title);
    }

    private function createTaskOn(TaskBoard $board, string $title): Task
    {
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function createSubtask(Task $parent, string $title): Task
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'parent_task_id' => $parent->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
