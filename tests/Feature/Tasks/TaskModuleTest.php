<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use InvalidArgumentException;
use Tests\TestCase;

class TaskModuleTest extends TestCase
{
    public function test_tasks_declares_the_hidden_module_contract_immediately_after_dashboard(): void
    {
        $registry = app(ModuleRegistry::class);
        $keys = array_keys($registry->all());
        $tasks = $registry->get('tasks');

        $this->assertSame(['dashboard', 'tasks'], array_slice($keys, 0, 2));
        $this->assertNotNull($tasks);
        $this->assertFalse($tasks->sidebar);
        $this->assertSame(
            ['view', 'create', 'edit', 'comment', 'delete', 'export', 'manage'],
            $tasks->actions,
        );
        $this->assertSame(Task::class, $tasks->subject?->model);
        $this->assertSame('task', $tasks->subject?->key());

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
        ], $tasks->triggerKeys());

        $actions = collect($tasks->automationActions)->keyBy('key');
        $this->assertSame([
            'task.create',
            'task.assign_owner',
            'task.set_status',
            'task.set_due_date',
            'task.move_group',
            'task.add_update',
        ], $actions->keys()->all());

        foreach ($actions as $key => $action) {
            $this->assertSame($key, $action->type());
            $this->assertSame($key !== 'task.create', $action->subjectBound);
        }
    }

    public function test_tasks_permissions_participate_in_the_normal_implication_contract(): void
    {
        $permissions = app(PermissionCatalogue::class);

        $this->assertContains('tasks.manage', $permissions->keys());
        $this->assertSame(
            ['tasks.edit', 'tasks.view'],
            $permissions->withImpliedView(['tasks.edit']),
        );
    }

    public function test_action_execution_identity_defaults_remain_backwards_compatible(): void
    {
        $generic = new ActionDefinition(
            key: 'job.update_field',
            label: 'Update a field',
            description: 'Changes one allowed field on the triggering job.',
        );
        $explicit = new ActionDefinition(
            key: 'task.create',
            label: 'Create a task',
            description: 'Creates follow-up work on a selected task board.',
            executionType: 'task.create',
            subjectBound: false,
        );

        $this->assertSame('update_field', $generic->type());
        $this->assertTrue($generic->subjectBound);
        $this->assertSame('task.create', $explicit->type());
        $this->assertFalse($explicit->subjectBound);
    }

    public function test_an_explicit_action_execution_type_uses_the_declaration_key_grammar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/execution type/');

        new ActionDefinition(
            key: 'task.create',
            label: 'Create a task',
            description: 'Creates follow-up work on a selected task board.',
            executionType: 'Task Create',
        );
    }
}
