<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Built-in column settings, several owners, names for groups and columns, and the Customer Experience one-off (Jose, 2026-10-20). */
class TaskBoardCustomerExperienceTest extends TestCase
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

    public function test_builtin_columns_default_and_patch_merges_and_validates(): void
    {
        $url = "/api/v1/task-boards/{$this->board->id}";
        $data = $this->api()->getJson($url)->assertOk()->json('data');
        $this->assertSame([
            'creator' => ['label' => 'Owner', 'visible' => true],
            'owner' => ['label' => 'Assigned', 'visible' => true],
            'status' => ['label' => 'Status', 'visible' => true],
            'due' => ['label' => 'Due', 'visible' => true],
            'about' => ['label' => 'About', 'visible' => true],
            'created' => ['label' => 'Created', 'visible' => false],
            'updates' => ['label' => 'Updates', 'visible' => true],
        ], $data['builtin_columns']);

        // An automatic board cannot be renamed, but its columns can be configured.
        $this->api()->patchJson($url, ['builtin_columns' => ['about' => ['label' => 'Summary']]])
            ->assertOk()->assertJsonPath('data.builtin_columns.about', ['label' => 'Summary', 'visible' => true]);
        $this->api()->patchJson($url, ['builtin_columns' => ['created' => ['visible' => true], 'about' => ['visible' => false]]])
            ->assertOk()
            ->assertJsonPath('data.builtin_columns.about', ['label' => 'Summary', 'visible' => false])
            ->assertJsonPath('data.builtin_columns.created.visible', true);

        $this->api()->patchJson($url, ['builtin_columns' => ['priority' => ['label' => 'X']]])->assertStatus(422);
        $this->api()->patchJson($url, ['builtin_columns' => ['due' => ['colour' => 'red']]])->assertStatus(422);
        $this->api()->patchJson($url, ['builtin_columns' => ['due' => ['visible' => 'yes']]])->assertStatus(422);
        $this->api()->patchJson($url, ['builtin_columns' => ['due' => ['label' => str_repeat('a', 61)]]])->assertStatus(422);
        $this->api()->patchJson($url, ['name' => 'Renamed'])->assertStatus(422); // still not renameable

        $viewer = $this->userWithRole($this->tenant, 'Viewer', ['tasks.view']);
        $this->signedInAs($viewer)->patchJson($url, ['builtin_columns' => ['due' => ['label' => 'When']]])->assertForbidden();
    }

    /** Jose 11-17: drag columns left or right; one order for everyone. */
    public function test_the_column_order_is_saved_for_the_board_and_keeps_the_labels(): void
    {
        $url = "/api/v1/task-boards/{$this->board->id}";
        $this->api()->patchJson($url, ['builtin_columns' => ['about' => ['label' => 'Summary']]])->assertOk();
        $this->api()->patchJson($url, ['column_order' => ['status', 'c:abc', 'owner']])
            ->assertOk()
            ->assertJsonPath('data.column_order', ['status', 'c:abc', 'owner'])
            ->assertJsonPath('data.builtin_columns.about.label', 'Summary');
        $this->api()->patchJson($url, ['builtin_columns' => ['due' => ['visible' => false]]])
            ->assertOk()->assertJsonPath('data.column_order', ['status', 'c:abc', 'owner']);
        $this->api()->patchJson($url, ['column_order' => ['status', 'status']])->assertStatus(422);

        $viewer = $this->userWithRole($this->tenant, 'Viewer', ['tasks.view']);
        $this->signedInAs($viewer)->patchJson($url, ['column_order' => ['due']])->assertForbidden();
    }

    public function test_a_saved_owner_label_survives_the_assigned_rename(): void
    {
        $this->inTenant($this->tenant['org'], fn () => $this->board->forceFill(['builtin_columns' => ['owner' => ['label' => 'Tech']]])->save());
        $data = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->assertOk()->json('data.builtin_columns');
        $this->assertSame(['label' => 'Tech', 'visible' => true], $data['owner']);
        $this->assertSame(['label' => 'Owner', 'visible' => true], $data['creator']);

        $this->api()->patchJson("/api/v1/task-boards/{$this->board->id}", ['builtin_columns' => ['creator' => ['label' => 'Created by']]])
            ->assertOk()->assertJsonPath('data.builtin_columns.creator.label', 'Created by');
    }

    public function test_created_by_names_the_creating_user_with_their_id(): void
    {
        $id = $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Mine'])
            ->assertCreated()->json('data.id');
        $admin = $this->tenant['admin'];
        $this->api()->getJson("/api/v1/tasks/{$id}")->assertOk()
            ->assertJsonPath('data.created_by', ['type' => 'user', 'id' => $admin->id, 'label' => $admin->name]);
    }

    public function test_tasks_carry_created_at_and_every_owner(): void
    {
        $second = $this->userWithRole($this->tenant, 'Second Owner', ['tasks.view', 'tasks.edit']);
        $res = $this->api()->postJson('/api/v1/tasks', [
            'board_id' => $this->board->id, 'title' => 'Two owners',
            'assignee_user_ids' => [$second->id, $this->tenant['admin']->id],
        ]);
        if ($res->status() === 422) {
            $this->fail($res->getContent());
        }
        $res->assertCreated()
            ->assertJsonPath('data.owner.id', $second->id)
            ->assertJsonPath('data.owners.0.id', $second->id)
            ->assertJsonPath('data.owners.1.id', $this->tenant['admin']->id);
        $this->assertNotNull($res->json('data.created_at'));
        $id = $res->json('data.id');

        // A lone assignee_user_id replaces the primary and keeps the others.
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['assignee_user_id' => $this->tenant['admin']->id])
            ->assertOk()->assertJsonPath('data.owners', [['id' => $this->tenant['admin']->id, 'name' => $this->tenant['admin']->name]]);
        $this->api()->patchJson("/api/v1/tasks/{$id}", ['assignee_user_ids' => []])
            ->assertOk()->assertJsonPath('data.owner', null)->assertJsonPath('data.owners', []);

        $board = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->json('data.tasks.0');
        $this->assertArrayHasKey('created_at', $board);

        $this->inTenant($this->tenant['org'], function () {
            $event = AutomationEvent::query()->where('event_key', 'task.created')->latest('id')->first();
            $this->assertCount(2, $event->payload['assignee_user_ids']);
        });
    }

    public function test_an_owner_outside_a_private_board_is_refused(): void
    {
        $owner = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $other = $this->userWithRole($this->tenant, 'Other Person', ['tasks.view']);
        $id = $this->signedInAs($owner)->postJson('/api/v1/task-boards', ['name' => 'Mine', 'visibility' => 'private'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($owner)->postJson('/api/v1/tasks', ['board_id' => $id, 'title' => 'X', 'assignee_user_ids' => [$owner->id, $other->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['assignee_user_ids.1' => ['Add Other Person to this board first.']]);
    }

    public function test_writer_takes_a_group_name_and_values_by_column_name(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $group = TaskGroup::query()->create(['board_id' => $this->board->id, 'name' => 'Customer Experience', 'position' => 9]);
            $col = TaskColumn::query()->create(['board_id' => $this->board->id, 'name' => 'Phone number', 'type' => 'text', 'position' => 1]);
            $task = app(TaskWriter::class)->create($this->board, [
                'title' => 'Named', 'group' => 'customer experience', 'values' => ['PHONE NUMBER' => '555-123-4567'],
            ], null);
            $this->assertSame($group->id, $task->group_id);
            $this->assertSame('555-123-4567', $task->values()->where('column_id', $col->id)->value('value_text'));

            try {
                app(TaskWriter::class)->create($this->board, ['title' => 'X', 'values' => ['Nope' => 'x']], null);
                $this->fail('An unknown column name was accepted.');
            } catch (ValidationException $e) {
                $this->assertSame('This board has no column called Nope.', $e->errors()['values.Nope'][0]);
            }
            try {
                app(TaskWriter::class)->create($this->board, ['title' => 'X', 'group' => 'Nope'], null);
                $this->fail('An unknown group name was accepted.');
            } catch (ValidationException $e) {
                $this->assertSame('This board has no group called Nope.', $e->errors()['group'][0]);
            }
        });
    }
}
