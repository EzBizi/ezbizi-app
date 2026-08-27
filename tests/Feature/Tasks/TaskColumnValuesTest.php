<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnValue;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskColumnValueStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskColumnValuesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    private Task $task;

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
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Typed work', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Typed task', 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->task = Task::findOrFail($taskId);
    }

    public function test_all_open_types_replace_clear_and_reject_wrong_shapes_while_file_stays_gated(): void
    {
        $columns = [];
        foreach (['text', 'number', 'checkbox', 'date', 'people', 'label', 'link', 'file'] as $type) {
            $columns[$type] = $this->createColumn(ucfirst($type), $type);
        }
        $label = $columns['label'];
        $optionId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$label->id}/options", [
            'name' => 'Installation',
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();

        $cases = [
            'text' => ['value' => 'Customer confirmed', 'expected' => 'Customer confirmed'],
            'number' => ['value' => 12.5, 'expected' => '12.500000'],
            'checkbox' => ['value' => true, 'expected' => true],
            'date' => ['value' => '2026-09-15', 'expected' => '2026-09-15'],
            'people' => ['value' => [$this->alpha['admin']->id], 'expected' => [$this->alpha['admin']->id]],
            'label' => ['value' => [$optionId], 'expected' => [$optionId]],
            'link' => [
                'value' => ['url' => 'https://EXAMPLE.test:443/path?q=1', 'label' => 'Docs'],
                'expected' => ['url' => 'https://example.test/path?q=1', 'label' => 'Docs'],
            ],
        ];
        foreach ($cases as $type => $case) {
            $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$columns[$type]->id}", [
                'expected_version' => $this->task->version,
                'value' => $case['value'],
            ])->assertOk()->assertJsonPath('data.value', $case['expected']);
            $this->enterTenant();
            $this->task = $this->task->fresh();
        }
        $this->assertSame(8, $this->task->version);
        $this->assertSame(7, TaskActivity::where('task_id', $this->task->id)
            ->where('kind', 'task.column_changed')->count());

        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$columns['text']->id}", [
            'expected_version' => $this->task->version, 'value' => null,
        ])->assertOk()->assertJsonPath('data.value', null);
        $this->enterTenant();
        $this->task = $this->task->fresh();
        $this->assertFalse(TaskColumnValue::where('task_id', $this->task->id)
            ->where('column_definition_id', $columns['text']->id)->exists());

        $invalid = [
            'number' => '12.5',
            'checkbox' => 1,
            'date' => '2026-02-30',
            'people' => 'not-a-list',
            'label' => [(string) Str::uuid()],
            'link' => ['url' => 'http://example.test'],
            'file' => [(string) Str::uuid()],
        ];
        foreach ($invalid as $type => $value) {
            $version = $this->task->version;
            $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$columns[$type]->id}", [
                'expected_version' => $version, 'value' => $value,
            ])->assertUnprocessable();
            $this->enterTenant();
            $this->assertSame($version, $this->task->fresh()->version, "{$type} mutated on invalid input.");
        }

        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$columns['number']->id}", [
            'expected_structure_version' => $this->board->structure_version,
            'type' => 'text',
        ])->assertUnprocessable();
    }

    public function test_foreign_column_ineligible_person_and_stale_value_fail_without_dependent_writes(): void
    {
        $people = $this->createColumn('Crew', 'people');
        $text = $this->createColumn('Notes', 'text');
        $franchiseOnly = $this->userWithRole(
            $this->alpha, 'Franchise only', ['tasks.view', 'tasks.edit'], onlyAt: $this->alpha['franchise'],
        );
        $this->enterTenant();
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$people->id}", [
            'expected_version' => $this->task->version,
            'value' => [$franchiseOnly->id],
        ])->assertUnprocessable();

        $stale = $this->task->version;
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$text->id}", [
            'expected_version' => $stale, 'value' => 'Current',
        ])->assertOk();
        $this->enterTenant();
        $this->task = $this->task->fresh();
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$text->id}", [
            'expected_version' => $stale, 'value' => 'Stale',
        ])->assertConflict()
            ->assertJsonPath('data.value', 'Current')
            ->assertJsonPath('meta.current_version', $this->task->version);

        $otherId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Other board', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $other = TaskBoard::findOrFail($otherId);
        $foreign = $this->createColumn('Foreign', 'text', $other);
        $version = $this->task->version;
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$foreign->id}", [
            'expected_version' => $version, 'value' => 'No',
        ])->assertNotFound();
        $this->enterTenant();
        $this->assertSame($version, $this->task->fresh()->version);
    }

    public function test_text_storage_boundary_and_domain_read_only_guard_fail_cleanly(): void
    {
        $text = $this->createColumn('Long notes', 'text');
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$text->id}", [
            'expected_version' => $this->task->version,
            'value' => str_repeat('x', 65535),
        ])->assertOk();
        $this->enterTenant();
        $this->task = $this->task->fresh();

        $version = $this->task->version;
        $this->putJson("/api/v1/tasks/{$this->task->id}/columns/{$text->id}", [
            'expected_version' => $version,
            'value' => str_repeat('x', 65536),
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->enterTenant();
        $this->assertSame($version, $this->task->fresh()->version);

        $this->alpha['org']->forceFill(['is_active' => false])->save();
        $actor = $this->alpha['admin']->fresh();
        try {
            app(TaskColumnValueStore::class)->replace(
                $actor,
                $this->task->fresh(),
                $text,
                ViewScope::forUser($actor),
                $version,
                'blocked',
            );
            $this->fail('Expected the domain writer to reject a read-only organization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('organization', $exception->errors());
        }
        $this->enterTenant();
        $this->assertSame($version, $this->task->fresh()->version);
    }

    private function createColumn(string $name, string $type, ?TaskBoard $board = null): TaskColumnDefinition
    {
        $board ??= $this->board;
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/columns", [
            'name' => $name, 'type' => $type,
            'expected_structure_version' => $board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        if ($board->id === $this->board->id) {
            $this->board = $this->board->fresh();
        }

        return TaskColumnDefinition::findOrFail($id);
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
