<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reorder, move, copy, bulk and undo-able delete (Jose, 2026-10-28). */
class TaskMoveCopyBulkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function board(array $tenant, string $kind, string $id): TaskBoard
    {
        return $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('kind', $kind)->where($kind.'_id', $id)->firstOrFail());
    }

    private function firstGroup(array $tenant, TaskBoard $board): TaskGroup
    {
        return $this->inTenant($tenant['org'], fn () => $board->groups()->first());
    }

    private function task(array $tenant, TaskBoard $board, string $title): Task
    {
        return $this->inTenant($tenant['org'], fn () => app(TaskWriter::class)->create($board, ['title' => $title], null));
    }

    private function titles(array $tenant, TaskBoard $board): array
    {
        return collect($this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$board->id}")->assertOk()->json('data.tasks'))
            ->where('board_id', $board->id)->pluck('title')->all();
    }

    public function test_a_reorder_persists(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $a = $this->task($tenant, $board, 'A');
        $this->task($tenant, $board, 'B');
        $this->task($tenant, $board, 'C');
        $this->assertSame(['A', 'B', 'C'], $this->titles($tenant, $board));

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$a->id}/move", ['group_id' => $a->group_id, 'position' => 2])->assertOk();
        $this->assertSame(['B', 'C', 'A'], $this->titles($tenant, $board));

        $other = $this->inTenant($tenant['org'], fn () => $board->groups()->skip(1)->first());
        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$a->id}/move", ['group_id' => $other->id, 'position' => 0])
            ->assertOk()->assertJsonPath('data.group_id', $other->id);
    }

    public function test_a_move_to_a_board_you_cannot_write_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($tenant, 'Alpha F2');
        $mine = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $theirs = $this->board($tenant, 'franchise', $sibling->id);
        $task = $this->task($tenant, $mine, 'Mine');
        $user = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit'], null, $tenant['franchise']);

        $this->signedInAs($user)->patchJson("/api/v1/tasks/{$task->id}/move", ['group_id' => $this->firstGroup($tenant, $theirs)->id])->assertStatus(422);
        $this->assertSame($mine->id, $this->inTenant($tenant['org'], fn () => $task->fresh()->board_id));

        // The Administrator moves it across, refiled to the new branch.
        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$task->id}/move", ['group_id' => $this->firstGroup($tenant, $theirs)->id])
            ->assertOk()->assertJsonPath('data.board_id', $theirs->id);
        $this->assertSame($sibling->id, $this->inTenant($tenant['org'], fn () => $task->fresh()->franchise_id));
    }

    public function test_copy_and_duplicate(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $company = $this->board($tenant, 'company', $tenant['company']->id);
        $task = $this->task($tenant, $board, 'Sweep');

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/copy")->assertCreated()
            ->assertJsonPath('data.board_id', $board->id)->assertJsonPath('data.title', 'Sweep');
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/copy", ['group_id' => $this->firstGroup($tenant, $company)->id])
            ->assertCreated()->assertJsonPath('data.board_id', $company->id);
        $this->assertSame(3, $this->inTenant($tenant['org'], fn () => Task::query()->where('title', 'Sweep')->count()));
    }

    public function test_bulk_does_what_it_may_and_reports_the_rest(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($tenant, 'Alpha F2');
        $mine = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Mine');
        $theirs = $this->task($tenant, $this->board($tenant, 'franchise', $sibling->id), 'Theirs');
        $user = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit'], null, $tenant['franchise']);

        $this->signedInAs($user)->postJson('/api/v1/tasks/bulk', ['ids' => [$mine->id, $theirs->id], 'op' => 'status', 'status' => 'done'])
            ->assertOk()->assertJsonPath('data.done', [$mine->id])->assertJsonPath('data.refused.0.id', $theirs->id);
        $this->inTenant($tenant['org'], function () use ($mine, $theirs) {
            $this->assertSame('done', $mine->fresh()->status);
            $this->assertSame('not_started', $theirs->fresh()->status);
        });
    }

    public function test_bulk_status_takes_a_board_status_by_id_as_one_task_does(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $one = $this->task($tenant, $board, 'One');
        $two = $this->task($tenant, $board, 'Two');
        $working = $this->inTenant($tenant['org'], fn () => $board->statuses()->where('name', 'Working on it')->firstOrFail());

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks/bulk', ['ids' => [$one->id, $two->id], 'op' => 'status', 'status_id' => $working->id])
            ->assertOk()->assertJsonPath('data.refused', [])->assertJsonCount(2, 'data.done');
        $this->inTenant($tenant['org'], function () use ($one, $two, $working) {
            $this->assertSame($working->id, $one->fresh()->status_id);
            $this->assertSame($working->id, $two->fresh()->status_id);
        });
    }

    public function test_delete_is_soft_and_restore_brings_it_and_its_subtasks_back(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $task = $this->task($tenant, $board, 'Parent');
        $child = $this->inTenant($tenant['org'], fn () => app(TaskWriter::class)->create($board, ['title' => 'Child', 'parent_task_id' => $task->id], null));

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks/bulk', ['ids' => [$task->id], 'op' => 'delete'])->assertOk();
        $this->inTenant($tenant['org'], fn () => $this->assertSame(2, Task::onlyTrashed()->whereIn('id', [$task->id, $child->id])->count()));

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/restore")->assertOk();
        $this->inTenant($tenant['org'], fn () => $this->assertSame(2, Task::query()->whereIn('id', [$task->id, $child->id])->count()));
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/restore")->assertNotFound();
    }
}
