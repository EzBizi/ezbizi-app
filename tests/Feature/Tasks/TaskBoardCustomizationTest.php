<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
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

        // A type change carries the values over (Jose, 2026-11-09); null clears.
        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}/columns/{$number}", ['type' => 'text'])->assertOk();
        $this->api()->getJson("/api/v1/tasks/{$id}")->assertJsonPath("data.values.{$number}", '12.5');
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$number => null]])->assertOk()
            ->assertJsonMissingPath("data.values.{$number}");
    }

    public function test_the_new_types_validate_and_read_back(): void
    {
        $col = fn (string $type, array $settings = []) => $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns",
            ['name' => ucfirst($type), 'type' => $type] + ($settings ? ['settings' => $settings] : []))->assertCreated()->json('data');
        $phone = $col('phone')['id'];
        $email = $col('email')['id'];
        $url = $col('url')['id'];
        $when = $col('datetime')['id'];
        $money = $col('currency')['id'];
        $long = $col('long_text')['id'];
        $tags = $col('tags', ['options' => [['label' => 'Complaint'], ['label' => 'Bad Review']]]);
        $link = $col('link')['id'];
        [$a, $b] = array_column($tags['settings']['options'], 'id');
        $customer = $this->inTenant($this->tenant['org'], fn () => \App\Models\Customer::create(
            ['owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C-9', 'name' => 'Ann Smith']));
        $id = $this->newTask();

        foreach ([$phone => 'call me', $email => 'nope', $url => 'ftp://x.y', $when => '2026-11-09', $money => 'ten',
            $tags['id'] => ['zzz'], $link => 'customer:not-a-uuid'] as $column => $bad) {
            $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [$column => $bad]])->assertStatus(422)->assertJsonValidationErrors("values.{$column}");
        }

        $this->api()->patchJson("/api/v1/tasks/{$id}", ['values' => [
            $phone => '(305) 724-2199', $email => 'ann@example.com', $url => 'https://example.com', $when => '2026-11-09 14:30',
            $money => '99.5', $long => str_repeat('a', 6000), $tags['id'] => [$a, $b], $link => 'customer:'.$customer->id,
        ]])->assertOk()
            ->assertJsonPath("data.values.{$phone}", '(305) 724-2199')
            ->assertJsonPath("data.values.{$money}", 99.5)
            ->assertJsonPath("data.values.{$tags['id']}", [$a, $b])
            ->assertJsonPath("data.values.{$link}.label", 'Ann Smith');
    }

    public function test_a_type_change_warns_before_it_clears_values_and_makes_labels_options(): void
    {
        $text = $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns", ['name' => 'Kind', 'type' => 'text'])
            ->assertCreated()->json('data.id');
        $one = $this->newTask(['values' => [$text => 'Complaint, Bad Review']]);
        $two = $this->newTask(['values' => [$text => 'abc']]);
        $url = "/api/v1/task-boards/{$this->board->id}/columns/{$text}";

        // To a number: 'abc' and the list are lost, so it asks first.
        $this->api()->patchJson($url, ['type' => 'number'])->assertStatus(422)
            ->assertJsonPath('errors.type.0', '2 values cannot become number and would be cleared.');

        // To tags: every label becomes an option, nothing lost.
        $options = $this->api()->patchJson($url, ['type' => 'tags'])->assertOk()->json('data.settings.options');
        $this->assertSame(['Complaint', 'Bad Review', 'abc'], array_column($options, 'label'));
        $this->assertCount(2, $this->api()->getJson("/api/v1/tasks/{$one}")->json("data.values.{$text}"));

        // Back to a checkbox loses both; accept_loss clears them.
        $this->api()->patchJson($url, ['type' => 'checkbox', 'accept_loss' => true])->assertOk();
        $this->api()->getJson("/api/v1/tasks/{$two}")->assertJsonMissingPath("data.values.{$text}");
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
