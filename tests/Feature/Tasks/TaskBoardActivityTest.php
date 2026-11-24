<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Reports\Xlsx;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Monday's board Activity Log (gap review P1, 11-24): every change on a board, with filters. */
class TaskBoardActivityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_board_log_lists_its_changes_newest_first_and_filters_by_column_and_person(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $tech = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $task = $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner'])->assertCreated()->json('data.id');
        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$task}", ['title' => 'Liner job'])->assertOk();
        $this->travel(1)->minutes();
        $this->signedInAs($tech)->patchJson("/api/v1/tasks/{$task}", ['status' => 'Done'])->assertOk();

        $get = fn (string $q = '') => collect($this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$board->id}/activity{$q}")->assertOk()->json('data'));
        $all = $get();
        $this->assertGreaterThanOrEqual(2, $all->count());
        $this->assertSame('Liner job', $all->first()['task'], 'each row names its task');
        $this->assertSame((string) $tech->id, (string) $all->first()['user_id'], 'newest first');

        $byTech = $get("?user_id={$tech->id}");
        $this->assertNotEmpty($byTech);
        $this->assertTrue($byTech->every(fn ($r) => (string) $r['user_id'] === (string) $tech->id));
        $statusLabel = $byTech->first()['label'];
        $this->assertTrue($get('?label='.urlencode($statusLabel))->every(fn ($r) => $r['label'] === $statusLabel));
        $this->assertCount(0, $get('?label=Nope'));
    }

    /** Monday's Export to Excel (gap review P1): the board, one group, or chosen tasks, labels by name. */
    public function test_export_writes_one_row_per_task_with_labels_by_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $api = fn () => $this->signedInAs($tenant['admin']);
        $stage = $api()->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Stage', 'type' => 'dropdown',
            'settings' => ['options' => [['id' => 'o1', 'label' => 'Waiting Parts']]]])->assertCreated()->json('data.id');
        $a = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Albert', 'values' => [$stage => 'o1']])->assertCreated()->json('data.id');
        $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Bea'])->assertCreated();

        $title = mb_substr(trim((string) preg_replace('/[^\w\- ]+/u', '', $board->name)), 0, 31);
        $sheet = Xlsx::read($api()->get("/api/v1/task-boards/{$board->id}/export")->assertOk()->getContent(), $title);
        $this->assertSame(['Task', 'Group', 'Status', 'Owners', 'Due', 'Stage'], array_slice($sheet[0], 0, 6));
        $albert = collect($sheet)->first(fn ($r) => ($r[0] ?? null) === 'Albert');
        $this->assertSame('Waiting Parts', $albert[5]);
        $this->assertCount(3, $sheet, 'a header and two tasks');

        $only = Xlsx::read($api()->get("/api/v1/task-boards/{$board->id}/export?ids[]={$a}")->getContent(), $title);
        $this->assertCount(2, $only, 'a header and the chosen task');
    }

    /** Monday's My Work (gap review P1): my open tasks on every board I see, by due day. */
    public function test_my_work_lists_my_open_tasks_across_boards_soonest_first(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $me = $tenant['admin'];
        $other = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $api = fn () => $this->signedInAs($me);
        $mk = fn (string $title, ?string $due, $owner) => $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => $title, 'due_on' => $due, 'assignee_user_id' => $owner->id])->assertCreated()->json('data.id');
        $mk('Later', now()->addDays(20)->toDateString(), $me);
        $mk('Today', now()->toDateString(), $me);
        $mk('No date', null, $me);
        $mk('Theirs', now()->toDateString(), $other);
        $done = $mk('Done one', now()->toDateString(), $me);
        $api()->patchJson("/api/v1/tasks/{$done}", ['status' => 'Done'])->assertOk();

        $titles = collect($api()->getJson('/api/v1/my-work')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['Today', 'Later', 'No date'], $titles);
        $this->assertContains('Done one', collect($api()->getJson('/api/v1/my-work?done=1')->json('data'))->pluck('title')->all());
    }

    /** Monday's status label editor (gap review P1): a default label, and retired ones. */
    public function test_a_new_task_starts_on_the_default_label_and_the_default_cannot_be_retired(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $api = fn () => $this->signedInAs($tenant['admin']);
        $statuses = collect($api()->getJson("/api/v1/task-boards/{$board->id}")->json('data.statuses'));
        $working = $statuses->firstWhere('name', 'Working on it')['id'];
        $stuck = $statuses->firstWhere('name', 'Stuck')['id'];

        $api()->patchJson("/api/v1/task-boards/{$board->id}/statuses/{$working}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $task = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'New'])->assertCreated()->json('data');
        $this->assertSame($working, $task['status_id']);

        $api()->patchJson("/api/v1/task-boards/{$board->id}/statuses/{$working}", ['retired' => true])->assertStatus(422);
        $api()->patchJson("/api/v1/task-boards/{$board->id}/statuses/{$stuck}", ['retired' => true])->assertOk()->assertJsonPath('data.retired', true);
        $this->assertSame(1, collect($api()->getJson("/api/v1/task-boards/{$board->id}")->json('data.statuses'))->where('is_default', true)->count());
    }

    /** Desk 11-24 (Eve deleted NW's "Open Tasks" on Monday): nothing makes it again, nor lands in the first group. */
    public function test_a_group_deleted_on_monday_is_not_made_again_and_work_goes_to_the_last_working_group(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], function () {
            $board = TaskBoard::query()->first();
            $board->groups()->delete();
            foreach (['Company Info', 'Field Manager', 'Done', 'Overbooked'] as $i => $name) {
                TaskGroup::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => $name, 'position' => $i]);
            }
            $board->forceFill(['builtin_columns' => ['_monday_deleted_groups' => ['open tasks']] + (array) $board->builtin_columns])->save();

            $group = app(TaskWriter::class)->openTasks($board->fresh());
            $this->assertSame('Overbooked', $group->name);
            $this->assertSame(0, $board->groups()->where('name', 'Open Tasks')->count());
        });
    }
}
