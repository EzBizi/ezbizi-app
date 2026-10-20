<?php

namespace Tests\Feature\Tasks;

use App\Console\Commands\TasksCustomerExperience;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'owner' => ['label' => 'Owner', 'visible' => true],
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
            $event = \App\Models\AutomationEvent::query()->where('event_key', 'task.created')->latest('id')->first();
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
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertSame('This board has no column called Nope.', $e->errors()['values.Nope'][0]);
            }
            try {
                app(TaskWriter::class)->create($this->board, ['title' => 'X', 'group' => 'Nope'], null);
                $this->fail('An unknown group name was accepted.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertSame('This board has no group called Nope.', $e->errors()['group'][0]);
            }
        });
    }

    public function test_title_parsing_is_certain_or_null(): void
    {
        // The two shapes read from production on 2026-10-20.
        $this->assertSame(['Nicole Johnson', '+14196779098', 'Chimney cap blew off; booked a repair'],
            TasksCustomerExperience::parseTitle('Complaint: Nicole Johnson +14196779098 - Chimney cap blew off; booked a repair'));
        $this->assertSame([null, '+14048243631', 'Wants Level 2 chimney inspection pricing'],
            TasksCustomerExperience::parseTitle('Complaint:  +14048243631 - Wants Level 2 chimney inspection pricing'));
        $this->assertSame(['Ann Lee', '+15552223333', 'Left 1 star'], TasksCustomerExperience::parseTitle('Bad review: Ann Lee +15552223333 - Left 1 star'));
        $this->assertNull(TasksCustomerExperience::parseTitle('Complaint: John Smith (555) 123-4567 - no E.164'));
        $this->assertNull(TasksCustomerExperience::parseTitle('Complaint: Ann +15552223333 no dash summary'));
        $this->assertNull(TasksCustomerExperience::parseTitle('complaint about the crew'));
        $this->assertNull(TasksCustomerExperience::parseTitle('Call back Mr Smith'));
    }

    private function seedTasks(): array
    {
        return $this->inTenant($this->tenant['org'], function () {
            $lead = Lead::query()->create([
                'company_id' => $this->tenant['company']->id, 'source' => 'manual', 'remote_id' => 'cx-test-1', 'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW,
                'contact_name' => 'Linda Lead', 'phone' => '+15550001111', 'received_at' => now(),
            ]);
            $w = app(TaskWriter::class);
            $make = fn (string $title, array $extra = []) => $w->create($this->board, ['title' => $title] + $extra, null)->id;

            $parent = $make('Complaint: Nicole Johnson +14196779098 - Chimney cap blew off; booked a repair');

            return [
                'plain' => $parent,
                'review' => $make('Bad review:  +14048243631 - Wants Level 2 chimney inspection pricing'),
                'linked' => $make('Complaint: Linda +15559990000 - Leak came back', ['about_type' => 'lead', 'about_id' => $lead->id]),
                'unparsed' => $make('complaint about the crew'),
                'other' => $make('Order ladders'),
                'sub' => $w->create($this->board, ['title' => 'Complaint: sub', 'parent_task_id' => $parent], null)->id,
            ];
        });
    }

    private function valuesOf(string $taskId): array
    {
        return $this->inTenant($this->tenant['org'], function () use ($taskId) {
            $cols = TaskColumn::query()->where('board_id', $this->board->id)->pluck('id', 'name');
            $task = Task::query()->with('values')->find($taskId);
            $v = $task->values->keyBy('column_id');

            return [
                'group' => TaskGroup::query()->find($task->group_id)?->name,
                'type' => isset($cols['Description']) ? $v->get($cols['Description'])?->value_text : null,
                'name' => isset($cols['Client name']) ? $v->get($cols['Client name'])?->value_text : null,
                'phone' => isset($cols['Phone number']) ? $v->get($cols['Phone number'])?->value_text : null,
                'title' => $task->title,
            ];
        });
    }

    public function test_the_command_groups_types_and_fills_and_is_idempotent(): void
    {
        $ids = $this->seedTasks();

        $this->artisan('ezbizi:tasks-customer-experience', ['board_id' => $this->board->id])
            ->expectsOutputToContain('matched:      4')
            ->expectsOutputToContain('moved:        4')
            ->expectsOutputToContain('typed:        4')
            ->expectsOutputToContain('phone filled: 3')
            ->expectsOutputToContain('name filled:  2')
            ->expectsOutputToContain('retitled:     3')
            ->expectsOutputToContain('unparsed:     1 '.$ids['unparsed'])
            ->assertSuccessful();

        $this->assertSame(['group' => 'Customer Experience', 'type' => 'complaint', 'name' => 'Nicole Johnson', 'phone' => '+14196779098',
            'title' => 'Chimney cap blew off; booked a repair'], $this->valuesOf($ids['plain']));
        $this->assertSame(['Customer Experience', 'bad_review', null, '+14048243631', 'Wants Level 2 chimney inspection pricing'], array_values($this->valuesOf($ids['review'])));
        // The linked lead wins over the title.
        $this->assertSame(['Customer Experience', 'complaint', 'Linda Lead', '+15550001111', 'Leak came back'], array_values($this->valuesOf($ids['linked'])));
        $this->assertSame(['Customer Experience', 'complaint', null, null, 'complaint about the crew'], array_values($this->valuesOf($ids['unparsed'])));
        $this->assertNotSame('Customer Experience', $this->valuesOf($ids['other'])['group']);
        $this->assertNull($this->valuesOf($ids['other'])['type']);
        $this->assertNull($this->valuesOf($ids['sub'])['type']); // subtasks are not matched; they follow their parent's group
        $this->assertSame('Customer Experience', $this->valuesOf($ids['sub'])['group']);

        $board = $this->api()->getJson("/api/v1/task-boards/{$this->board->id}")->json('data');
        $this->assertSame('Summary', $board['builtin_columns']['about']['label']);
        $this->assertTrue($board['builtin_columns']['created']['visible']);
        $type = collect($board['columns'])->firstWhere('name', 'Description');
        $this->assertSame('status', $type['type']);
        $this->assertSame([['id' => 'complaint', 'label' => 'Complaint', 'colour' => '#E5484D'], ['id' => 'bad_review', 'label' => 'Bad Review', 'colour' => '#F76B15']],
            $type['settings']['options']);

        $before = $this->inTenant($this->tenant['org'], fn () => [TaskColumn::query()->count(), TaskGroup::query()->count(), \App\Models\AuditEvent::query()->count()]);
        $this->artisan('ezbizi:tasks-customer-experience', ['board_id' => $this->board->id])
            ->expectsOutputToContain('matched:      1') // retitled tasks no longer start with the prefix
            ->expectsOutputToContain('moved:        0')
            ->expectsOutputToContain('typed:        0')
            ->expectsOutputToContain('phone filled: 0')
            ->expectsOutputToContain('name filled:  0')
            ->expectsOutputToContain('retitled:     0')
            ->assertSuccessful();
        $this->assertSame($before, $this->inTenant($this->tenant['org'], fn () => [TaskColumn::query()->count(), TaskGroup::query()->count(), \App\Models\AuditEvent::query()->count()]));
    }

    public function test_dry_run_changes_nothing_and_the_column_name_is_an_option(): void
    {
        $ids = $this->seedTasks();
        $snapshot = fn () => $this->inTenant($this->tenant['org'], fn () => [
            TaskColumn::query()->count(), TaskGroup::query()->count(),
            Task::query()->orderBy('id')->pluck('group_id')->all(), $this->board->fresh()->builtin_columns,
        ]);
        $before = $snapshot();

        $this->artisan('ezbizi:tasks-customer-experience', ['board_id' => $this->board->id, '--dry-run' => true])
            ->expectsOutputToContain('[dry run, nothing saved]')
            ->expectsOutputToContain('moved:        4')
            ->assertSuccessful();
        $this->assertSame($before, $snapshot());

        $this->artisan('ezbizi:tasks-customer-experience', ['board_id' => $this->board->id, '--column-name' => 'Descripción'])->assertSuccessful();
        $this->inTenant($this->tenant['org'], fn () => $this->assertTrue(
            TaskColumn::query()->where('board_id', $this->board->id)->where('name', 'Descripción')->where('type', 'status')->exists()
        ));
    }
}
