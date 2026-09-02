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
use App\Support\Modules\ModuleRegistry;
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

    // ── the published contract (Task 8) ──────────────────────────
    //
    // Tasks 4 and 6 each registered their own Phase 2 trigger in
    // ModuleServiceProvider as they went — emitting an unregistered trigger
    // throws immediately, so deferring registration to this task was never
    // possible. What was still open is whether the four keys below are the
    // ones actually registered, whether they read on the Task subject, and
    // whether nothing else about the module's published shape moved while
    // they were added. These assertions are read from
    // `app(ModuleRegistry::class)`, not retyped from memory of the plan.

    public function test_phase_2_triggers_are_declared_israised_and_apply_to_the_task_subject(): void
    {
        $registry = app(ModuleRegistry::class);
        $tasks = $registry->get('tasks');
        $this->assertNotNull($tasks);

        foreach ([
            'task.dependency_added',
            'task.dependency_removed',
            'task.recurrence_completed',
            'task.recurrence_instance_due',
        ] as $key) {
            $trigger = $tasks->trigger($key);
            $this->assertNotNull($trigger, "Phase 2 trigger '{$key}' is not declared on the tasks module.");
            $this->assertTrue($trigger->isRaised, "'{$key}' is declared but marked as never raised.");

            // subjectOfTrigger is what the field picker actually calls — the
            // same lookup the save-time check uses to decide whether a rule's
            // update_field action may write to this record.
            $this->assertSame(
                'task',
                $registry->subjectOfTrigger($key)?->key(),
                "'{$key}' does not resolve to the Task subject.",
            );
        }
    }

    /**
     * The full Phase 1 catalogue, spelled out rather than counted, so that a
     * future task adding or renaming a trigger or action has to edit this
     * assertion on purpose rather than drift past a count that still matches.
     */
    public function test_phase_1_triggers_and_actions_are_unchanged(): void
    {
        $tasks = app(ModuleRegistry::class)->get('tasks');
        $this->assertNotNull($tasks);

        $this->assertSame([
            'task.created',
            'task.owner_changed',
            'task.status_changed',
            'task.due_date_changed',
            'task.completed',
            'task.reopened',
            'task.update_added',
            'task.archived',
            'task.overdue',
            // Phase 2 additions, appended after the original nine.
            'task.dependency_added',
            'task.dependency_removed',
            'task.recurrence_completed',
            'task.recurrence_instance_due',
        ], $tasks->triggerKeys());

        $this->assertSame(
            ['task.create', 'task.assign_owner', 'task.set_status', 'task.set_due_date', 'task.move_group', 'task.add_update'],
            array_map(fn ($action) => $action->key, $tasks->automationActions),
        );
    }

    /**
     * Creating a recurring instance is what TaskDueWorker does, not something
     * a rule invokes — so Phase 2 declares zero new actions. An assertion that
     * only checked the six original keys were still PRESENT would pass with a
     * seventh added; this also checks the count.
     */
    public function test_no_new_actions_were_added_by_phase_2(): void
    {
        $tasks = app(ModuleRegistry::class)->get('tasks');
        $this->assertNotNull($tasks);

        $this->assertCount(6, $tasks->automationActions);
    }

    /**
     * Exactly the seven Phase 1 permissions — plan §14: Phase 2 adds no
     * capability those do not already describe. A permission added by
     * accident here is a data migration across every role in every
     * organization later, so this checks the set is exact, not merely a
     * superset of the seven.
     */
    public function test_permission_set_is_exactly_the_seven_phase_1_permissions(): void
    {
        $tasks = app(ModuleRegistry::class)->get('tasks');
        $this->assertNotNull($tasks);

        $this->assertSame(
            ['view', 'create', 'edit', 'comment', 'delete', 'export', 'manage'],
            $tasks->actions,
        );
        $this->assertSame(
            ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.comment', 'tasks.delete', 'tasks.export', 'tasks.manage'],
            $tasks->permissionKeys(),
        );
    }

    /**
     * The frozen v0.0.0112 client tolerates an unknown trigger key generically
     * — `CatalogueTrigger.tryFrom` falls back to `label: json['label'] ?? key`
     * (docs/ACTIVE-CONSTRAINTS.md §1) — but only because the catalogue still
     * SERIALIZES the shape it expects: `key`, `label`, `description` per
     * trigger. This hits the real `/api/v1/automations/catalogue` endpoint
     * rather than the registry object directly, so a change to the
     * controller's own mapping would fail this too, not just a change to the
     * module declaration.
     */
    public function test_catalogue_endpoint_serializes_phase_2_triggers_in_the_shape_the_frozen_client_reads(): void
    {
        $tenant = $this->provisionTenant('Gamma');
        app(TenantContext::class)->set($tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->actingAs($tenant['admin']->fresh());

        $response = $this->getJson('/api/v1/automations/catalogue')->assertOk();
        $tasksModule = collect($response->json('data.modules'))->firstWhere('key', 'tasks');
        $this->assertNotNull($tasksModule);

        foreach ($tasksModule['triggers'] as $trigger) {
            $this->assertArrayHasKey('key', $trigger);
            $this->assertArrayHasKey('label', $trigger);
            $this->assertArrayHasKey('description', $trigger);
        }

        $triggerKeys = collect($tasksModule['triggers'])->pluck('key')->all();
        foreach ([
            'task.dependency_added',
            'task.dependency_removed',
            'task.recurrence_completed',
            'task.recurrence_instance_due',
        ] as $key) {
            $this->assertContains($key, $triggerKeys);
        }

        // No new action keys reach the wire either — automation_builder.dart
        // enumerates `knownTypes` by exact key rather than by prefix, so a new
        // task.* action would not be inert the way a new trigger is.
        $this->assertSame(
            ['task.create', 'task.assign_owner', 'task.set_status', 'task.set_due_date', 'task.move_group', 'task.add_update'],
            collect($tasksModule['declared_actions'])->pluck('key')->all(),
        );
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
