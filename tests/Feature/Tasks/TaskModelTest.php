<?php

namespace Tests\Feature\Tasks;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskDueReminderOccurrence;
use App\Models\TaskNotificationIntent;
use App\Models\TaskOverdueOccurrence;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateVersion;
use App\Models\TaskView;
use App\Support\Database\WrittenOnceRefusal;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function taskModels(): iterable
    {
        foreach ([
            'TaskFolder', 'TaskBoard', 'TaskBoardMember', 'TaskBoardPreference',
            'TaskGroup', 'TaskCoreColumnSetting', 'TaskStatusOption',
            'TaskPriorityOption', 'TaskColumnDefinition', 'TaskColumnOption',
            'Task', 'TaskColumnValue', 'TaskColumnPerson', 'TaskColumnLabel',
            'TaskColumnFile', 'TaskCollaborator', 'TaskWatchPreference',
            'TaskUpdate', 'TaskUpdateVersion', 'TaskUpdateMention',
            'TaskUpdateAttachmentCandidate', 'TaskActivity', 'TaskRecordLink',
            'TaskView', 'TaskViewPreference', 'TaskOverdueOccurrence',
            'TaskDueReminderOccurrence', 'TaskNotificationIntent', 'TaskImportSession',
        ] as $name) {
            yield $name => ["App\\Models\\{$name}"];
        }
    }

    #[DataProvider('taskModels')]
    public function test_every_tasks_table_has_a_tenant_scoped_model_and_an_explicit_retention_decision(string $class): void
    {
        $this->assertTrue(class_exists($class), "{$class} does not exist.");
        $this->assertTrue(is_subclass_of($class, Model::class));
        $this->assertContains(BelongsToTenant::class, class_uses_recursive($class));
        $this->assertTrue(is_subclass_of($class, StatesRetention::class));
        $this->assertNotEmpty($class::retention()->sentence());
    }

    public function test_transaction_owned_versions_identity_and_order_are_not_mass_assignable(): void
    {
        $this->assertNotMassAssignable(new Task, [
            'organization_id', 'board_id', 'group_id', 'parent_task_id', 'number',
            'position', 'version', 'detail_version', 'due_revision', 'archived_with_parent_id',
        ]);
        $this->assertNotMassAssignable(new TaskBoard, ['organization_id', 'position', 'structure_version']);
        $this->assertNotMassAssignable(new TaskUpdate, ['organization_id', 'version']);
        $this->assertNotMassAssignable(new TaskView, [
            'organization_id', 'position', 'version', 'is_permanent_main',
        ]);
        $this->assertNotMassAssignable(new TaskNotificationIntent, [
            'organization_id', 'status', 'claim_token', 'claimed_until', 'attempts', 'last_error_code',
        ]);
    }

    public function test_core_relationships_and_casts_are_explicit(): void
    {
        $task = new Task;
        $this->assertInstanceOf(BelongsTo::class, $task->board());
        $this->assertInstanceOf(BelongsTo::class, $task->group());
        $this->assertInstanceOf(BelongsTo::class, $task->parent());
        $this->assertInstanceOf(HasMany::class, $task->subtasks());
        $this->assertInstanceOf(HasMany::class, $task->updates());

        $this->assertSame('date', $task->getCasts()['due_on']);
        $this->assertSame('integer', $task->getCasts()['version']);
        $this->assertSame('array', (new TaskView)->getCasts()['configuration']);
        $this->assertSame('array', (new TaskActivity)->getCasts()['data']);
    }

    public function test_board_and_task_factories_build_their_domain_models(): void
    {
        $this->assertInstanceOf(TaskBoard::class, TaskBoard::factory()->make());
        $this->assertInstanceOf(Task::class, Task::factory()->make());
    }

    public function test_task_model_rejects_becoming_its_own_parent(): void
    {
        $task = new Task;
        $task->forceFill(['id' => '01999999-9999-7999-8999-999999999999', 'parent_task_id' => '01999999-9999-7999-8999-999999999999']);
        $task->exists = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be its own parent');

        $task->save();
    }

    /** @return iterable<string, array{class-string<Model>}> */
    public static function immutableModels(): iterable
    {
        yield TaskUpdateVersion::class => [TaskUpdateVersion::class];
        yield TaskActivity::class => [TaskActivity::class];
        yield TaskOverdueOccurrence::class => [TaskOverdueOccurrence::class];
        yield TaskDueReminderOccurrence::class => [TaskDueReminderOccurrence::class];
    }

    /** @param class-string<Model> $class */
    #[DataProvider('immutableModels')]
    public function test_history_and_occurrence_models_refuse_bulk_mutation(string $class): void
    {
        $this->assertContains(WrittenOnce::class, class_uses_recursive($class));

        $this->expectException(WrittenOnceRefusal::class);
        $class::withoutGlobalScopes()->update(['created_at' => now()]);
    }

    private function assertNotMassAssignable(Model $model, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $this->assertNotContains($attribute, $model->getFillable(), $model::class." exposes {$attribute} to mass assignment.");
        }
    }
}
