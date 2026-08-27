<?php

namespace Tests\Feature\Tasks;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Models\TaskStatusOption;
use App\Models\TaskUpdate;
use App\Support\Automations\AutomationEngine;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_all_task_actions_run_as_the_creator_in_the_saved_scope_and_test_mode_is_inert(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$tenant['company']->id],
        ])->save();
        $this->actingAs($tenant['admin']->fresh());

        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations',
            'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($tenant['org']);
        $board = TaskBoard::findOrFail($boardId);
        $source = $board->groups()->active()->where('name', 'To do')->firstOrFail();
        $destinationId = $this->postJson("/api/v1/tasks/boards/{$board->id}/groups", [
            'name' => 'Waiting',
            'color' => '#B05F00',
            'expected_structure_version' => 1,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($tenant['org']);
        $destination = TaskGroup::findOrFail($destinationId);
        $notStarted = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $active = $board->statusOptions()->active()->where('category', StatusCategory::Active)->firstOrFail();

        $task = $this->createTask($board, $source, $notStarted, 'Original');
        $event = AutomationEvent::where('event_key', 'task.created')->where('subject_id', $task->id)->firstOrFail();

        $automationId = $this->postJson('/api/v1/automations', [
            'name' => 'Task workflow',
            'trigger_key' => 'task.created',
            'scope_type' => 'company',
            'scope_id' => $tenant['company']->id,
            'actions' => [
                ['type' => 'task.assign_owner', 'owner_user_id' => $tenant['admin']->id],
                ['type' => 'task.set_status', 'status_option_id' => $active->id],
                ['type' => 'task.set_due_date', 'due_on' => '2026-09-15'],
                ['type' => 'task.move_group', 'group_id' => $destination->id],
                ['type' => 'task.add_update', 'body' => '<p>Started automatically.</p>'],
                [
                    'type' => 'task.create',
                    'board_id' => $board->id,
                    'group_id' => $destination->id,
                    'status_option_id' => $notStarted->id,
                    'title' => 'Automatic follow-up',
                ],
            ],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/automations/{$automationId}", ['is_active' => true])->assertOk();
        app(TenantContext::class)->set($tenant['org']);

        // A franchise view cannot see company-level General work. Execution must
        // therefore use the rule's saved company scope, never this navigation preference.
        $tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::FRANCHISE.':'.$tenant['franchise']->id],
        ])->save();

        $this->assertSame(1, app(AutomationEngine::class)->deliver($event));
        $task = $task->fresh();
        $this->assertSame($tenant['admin']->id, $task->owner_user_id);
        $this->assertSame($active->id, $task->status_option_id);
        $this->assertSame('2026-09-15', $task->due_on?->format('Y-m-d'));
        $this->assertSame($destination->id, $task->group_id);
        $this->assertSame(1, TaskUpdate::where('task_id', $task->id)->count());
        $this->assertTrue(Task::where('title', 'Automatic follow-up')->where('board_id', $board->id)->exists());

        $caused = AutomationEvent::where('causation_id', $event->id)->get();
        $this->assertNotEmpty($caused);
        $this->assertTrue($caused->every(fn (AutomationEvent $child) => $child->rootId() === $event->id));
        $this->assertTrue($caused->every(fn (AutomationEvent $child) => $child->actor_id === $tenant['admin']->id));

        $untouched = $this->createTask($board, $source->fresh(), $notStarted, 'Test-mode subject');
        $testEvent = AutomationEvent::where('event_key', 'task.created')->where('subject_id', $untouched->id)->firstOrFail();
        $followUps = Task::where('title', 'Automatic follow-up')->count();
        $automation = Automation::findOrFail($automationId);
        $result = app(AutomationEngine::class)->rehearse($automation, $testEvent);

        $this->assertTrue($result['matched']);
        $this->assertCount(6, $result['would_do']);
        $this->assertTrue(collect($result['would_do'])->every(fn (array $action) => $action['skipped'] === true));
        $untouched = $untouched->fresh();
        $this->assertNull($untouched->owner_user_id);
        $this->assertNull($untouched->due_on);
        $this->assertSame($source->id, $untouched->group_id);
        $this->assertSame(0, TaskUpdate::where('task_id', $untouched->id)->count());
        $this->assertSame($followUps, Task::where('title', 'Automatic follow-up')->count());
    }

    private function createTask(
        TaskBoard $board,
        TaskGroup $group,
        TaskStatusOption $status,
        string $title,
    ): Task {
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title,
            'group_id' => $group->id,
            'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($board->organization);

        return Task::findOrFail($id);
    }
}
