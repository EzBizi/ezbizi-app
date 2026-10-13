<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskBoardCustomizationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        $this->board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $this->tenant['franchise']->id)->first());
    }

    private function api()
    {
        return $this->signedInAs($this->tenant['admin']);
    }

    private function newTask(array $extra = []): string
    {
        return $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'T'] + $extra)
            ->assertCreated()->json('data.id');
    }

    public function test_board_serves_four_built_in_statuses_and_company_on_index(): void
    {
        $data = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->assertOk()->json('data');

        $this->assertSame(['Not started', 'Working on it', 'Stuck', 'Done'], array_column($data['statuses'], 'name'));
        $this->assertSame([false, false, false, true], array_column($data['statuses'], 'is_done'));
        $this->assertSame([], $data['columns']);

        $row = collect($this->api()->getJson('/api/v1/task-boards')->json('data'))->firstWhere('id', $this->board->id);
        $this->assertSame($this->tenant['company']->id, $row['company_id']);
        $this->assertSame('Alpha Co', $row['company_name']);
    }

    public function test_custom_done_status_sets_and_clears_completed_at_and_maps_tasks_status(): void
    {
        $shipped = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/statuses", ['name' => 'Shipped', 'is_done' => true])
            ->assertCreated()->json('data.id');
        $review = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/statuses", ['name' => 'In review'])
            ->assertCreated()->json('data.id');
        $id = $this->newTask();

        $this->api()->patchJson("/api/v1/tasks/{$id}", ['status_id' => $shipped])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.status_id', $shipped);
        $this->inTenant($this->tenant['org'], fn () => $this->assertNotNull(Task::find($id)->completed_at));

        $this->api()->patchJson("/api/v1/tasks/{$id}", ['status_id' => $review])
            ->assertOk()->assertJsonPath('data.status', 'not_started')->assertJsonPath('data.completed_at', null);

        // By name, any case, on the task's own board; an unknown name is refused.
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['status' => 'shipped'])->assertOk()->assertJsonPath('data.status_id', $shipped);
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['status' => 'Nope'])
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'This board has no status called Nope.');

        // Editing is_done re-derives every task on the status.
        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}/statuses/{$shipped}", ['is_done' => false])->assertOk();
        $this->inTenant($this->tenant['org'], function () use ($id) {
            $task = Task::find($id);
            $this->assertSame('not_started', $task->status);
            $this->assertNull($task->completed_at);

            $event = AutomationEvent::query()->where('event_key', 'task.status_changed')->first();
            $this->assertSame('Not started', $event->payload['before']['status']);
            $this->assertSame('Shipped', $event->payload['after']['status']);
            $this->assertTrue($event->payload['after']['is_done']);

            $this->assertContains('Shipped', TaskStatuses::namesIn('franchise', $this->tenant['franchise']->id));
            // A company's scope reaches its franchises' boards; a stranger's franchise id reaches nothing.
            $this->assertContains('In review', TaskStatuses::namesIn('company', $this->tenant['company']->id));
            $this->assertSame([], TaskStatuses::namesIn('franchise', '00000000-0000-0000-0000-000000000000'));
            $this->assertContains('Working on it', TaskStatuses::namesIn('organization', null));
        });
    }

    public function test_deleting_a_status_in_use_needs_move_to(): void
    {
        $statuses = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->json('data.statuses');
        [$notStarted, $stuck] = [$statuses[0]['id'], $statuses[2]['id']];
        $id = $this->newTask(['status_id' => $stuck]);

        $url = "/api/v1/task-boards/{$this->board->id}/statuses/{$stuck}";
        $this->api()->deleteJson($url)->assertStatus(422)->assertJsonValidationErrors('move_to');
        $this->api()->deleteJson($url, ['move_to' => $notStarted])->assertNoContent();

        $this->inTenant($this->tenant['org'], fn () => $this->assertSame($notStarted, Task::find($id)->status_id));
    }

    public function test_column_values_are_validated_by_type(): void
    {
        $number = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns", ['name' => 'Cost', 'type' => 'number'])
            ->assertCreated()->json('data.id');
        $dropdown = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns", [
            'name' => 'Size', 'type' => 'dropdown', 'settings' => ['options' => [['label' => 'Small', 'colour' => '#fff'], ['label' => 'Large']]],
        ])->assertCreated()->assertJsonPath('data.hidden', false)->json('data');
        $check = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns", ['name' => 'OK', 'type' => 'checkbox'])
            ->assertCreated()->json('data.id');
        $small = $dropdown['settings']['options'][0]['id'];
        $this->assertNotEmpty($small);
        $id = $this->newTask();

        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$number => 'abc']])->assertStatus(422)->assertJsonValidationErrors("values.{$number}");
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$dropdown['id'] => 'Small']])->assertStatus(422);

        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$number => '12.5', $dropdown['id'] => $small, $check => true]])
            ->assertOk()
            ->assertJsonPath("data.values.{$number}", 12.5)
            ->assertJsonPath("data.values.{$dropdown['id']}", $small)
            ->assertJsonPath("data.values.{$check}", true);

        // Type cannot change once values exist; null clears.
        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}/columns/{$number}", ['type' => 'text'])->assertStatus(422);
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$number => null]])->assertOk()
            ->assertJsonMissingPath("data.values.{$number}");
    }

    public function test_subtasks_are_one_level_on_the_same_board_and_go_with_their_parent(): void
    {
        $parent = $this->newTask();
        $child = $this->newTask(['parent_task_id' => $parent]);
        $this->newTask(['parent_task_id' => $parent, 'status' => 'done']);

        $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'x', 'parent_task_id' => $child])
            ->assertStatus(422)->assertJsonValidationErrors('parent_task_id');

        $other = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('owner_user_id', $this->tenant['admin']->id)->first());
        $this->api()->postJson('/api/v1/tasks', ['board_id' => $other->id, 'title' => 'x', 'parent_task_id' => $parent])
            ->assertStatus(422);

        $tasks = collect($this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->json('data.tasks'));
        $this->assertSame(2, $tasks->firstWhere('id', $parent)['subtasks_count']);
        $this->assertSame(1, $tasks->firstWhere('id', $parent)['subtasks_done']);
        $this->assertSame($parent, $tasks->firstWhere('id', $child)['parent_task_id']);

        $this->api()->deleteJson("/api/v1/tasks/{$parent}")->assertNoContent();
        $this->inTenant($this->tenant['org'], fn () => $this->assertTrue(Task::withTrashed()->find($child)->trashed()));
    }

    public function test_groups_can_be_renamed_and_a_non_empty_one_needs_move_to(): void
    {
        $groups = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->json('data.groups');
        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}/groups/{$groups[0]['id']}", ['name' => 'Today'])
            ->assertOk()->assertJsonPath('data.name', 'Today');
        $this->newTask(['group_id' => $groups[0]['id']]);

        $url = "/api/v1/task-boards/{$this->board->id}/groups/{$groups[0]['id']}";
        $this->api()->deleteJson($url)->assertStatus(422);
        $this->api()->deleteJson($url, ['move_to' => $groups[1]['id']])->assertNoContent();
    }
}
