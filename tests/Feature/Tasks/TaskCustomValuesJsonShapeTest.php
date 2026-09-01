<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Regression coverage for the production incident where a task with NO
 * custom column values broke the Tasks screen entirely.
 *
 * `custom_values` is built server-side as `$custom = []`. PHP's
 * `json_encode()` renders an empty PHP array as a JSON ARRAY (`[]`), never
 * an object, no matter how the array is typed or declared. The Flutter
 * client's `_optionalObject(json, 'custom_values')`
 * (client/lib/screens/tasks/task_models.dart) requires a JSON OBJECT and
 * throws on a List — so any task with zero custom values broke parsing for
 * the whole response, not just that task.
 *
 * This suite deliberately does NOT use `assertJsonPath()` /
 * `json_decode($content, true)` to check the shape: decoding with the
 * associative flag erases the `[]` vs `{}` distinction (both become an
 * empty PHP array), which is exactly why the original bug shipped past a
 * full test suite. Instead these tests either inspect the raw response
 * body string, or `json_decode()` WITHOUT the associative flag (the
 * default), which keeps a JSON object as `stdClass` and a JSON array as a
 * PHP `array` — the one decode that can actually tell the two apart.
 */
class TaskCustomValuesJsonShapeTest extends TestCase
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
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Shape check', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
    }

    private function createTask(string $title): Task
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($taskId);
    }

    private function createColumn(string $name, string $type): TaskColumnDefinition
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => $name, 'type' => $type,
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();

        return TaskColumnDefinition::findOrFail($id);
    }

    public function test_rows_endpoint_emits_an_empty_object_not_an_array_when_a_task_has_no_custom_values(): void
    {
        $task = $this->createTask('No custom values');

        $response = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows")->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('"custom_values":[]', $raw);
        $this->assertStringContainsString('"custom_values":{}', $raw);

        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $row = collect($decoded->data)->first(fn ($row) => $row->id === $task->id);
        $this->assertNotNull($row, 'the created task must appear in the rows response');
        $this->assertIsObject($row->custom_values, 'custom_values must decode as a JSON object, not an array');
    }

    public function test_detail_endpoint_emits_an_empty_object_not_an_array_when_a_task_has_no_custom_values(): void
    {
        $task = $this->createTask('No custom values detail');

        $response = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/tasks/{$task->id}")->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('"custom_values":[]', $raw);
        $this->assertStringContainsString('"custom_values":{}', $raw);

        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($decoded->data->custom_values, 'custom_values must decode as a JSON object, not an array');
    }

    public function test_rows_endpoint_still_returns_populated_custom_values_correctly(): void
    {
        $column = $this->createColumn('Notes', 'text');
        $task = $this->createTask('Has custom values');
        $this->putJson("/api/v1/tasks/{$task->id}/columns/{$column->id}", [
            'expected_version' => $task->version, 'value' => 'Confirmed on site',
        ])->assertOk();
        $this->enterTenant();

        $response = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/rows")->assertOk();

        $raw = $response->getContent();
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $row = collect($decoded->data)->first(fn ($row) => $row->id === $task->id);
        $this->assertNotNull($row);
        $this->assertIsObject($row->custom_values);
        $this->assertSame('Confirmed on site', $row->custom_values->{$column->id});
    }

    public function test_detail_endpoint_still_returns_populated_custom_values_correctly(): void
    {
        $column = $this->createColumn('Notes', 'text');
        $task = $this->createTask('Has custom values detail');
        $this->putJson("/api/v1/tasks/{$task->id}/columns/{$column->id}", [
            'expected_version' => $task->version, 'value' => 'Confirmed on site',
        ])->assertOk();
        $this->enterTenant();

        $response = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/tasks/{$task->id}")->assertOk();

        $raw = $response->getContent();
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($decoded->data->custom_values);
        $this->assertSame('Confirmed on site', $decoded->data->custom_values->{$column->id});
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }
}
