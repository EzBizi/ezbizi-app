<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Support\Reports\Xlsx;
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
}
