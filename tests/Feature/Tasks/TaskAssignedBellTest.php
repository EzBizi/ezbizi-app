<?php

namespace Tests\Feature\Tasks;

use App\Models\Notification;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 11-17: Ana and Tiara got no bell for the tasks a rule gave them. */
class TaskAssignedBellTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_whoever_is_newly_given_a_task_gets_a_bell_and_the_giver_and_the_mirror_do_not(): void
    {
        $t = $this->provisionTenant('Alpha');
        $org = $t['org'];
        $jose = $t['admin'];
        $ana = $this->userWithRole($t, 'Dispatcher', ['tasks.view', 'tasks.edit']);

        $this->inTenant($org, function () use ($t, $jose, $ana) {
            $board = app(TaskBoards::class)->ensureFor($t['franchise']);
            $writer = app(TaskWriter::class);

            // A rule's task (no actor) rings for its owner.
            $task = $writer->create($board, ['title' => 'Call back Ann', 'assignee_user_ids' => [$ana->id]], null);
            $bell = Notification::query()->where('user_id', $ana->id)->sole();
            $this->assertSame('New task: Call back Ann', $bell->title);
            $this->assertSame($task->id, $bell->subject_id);

            // Adding Jose by Jose himself tells nobody; Ana is not told twice.
            $writer->update($task, ['assignee_user_ids' => [$ana->id, $jose->id]], $jose);
            $this->assertSame(1, Notification::query()->count());

            // The Monday mirror is quiet.
            $quiet = app(TaskWriter::class);
            $quiet->quiet = true;
            $quiet->create($board, ['title' => 'From Monday', 'assignee_user_ids' => [$ana->id]], null);
            $this->assertSame(1, Notification::query()->count());
        });
    }

    /** Desk 11-19: Jose's "Assigned to me" was empty: a task bell carries its kind and the tab finds it. */
    public function test_a_task_assignment_bell_shows_under_assigned_to_me(): void
    {
        $t = $this->provisionTenant('Alpha');
        $ana = $this->userWithRole($t, 'Dispatcher', ['tasks.view', 'tasks.edit']);
        $this->inTenant($t['org'], fn () => app(TaskWriter::class)->create(app(TaskBoards::class)->ensureFor($t['franchise']), ['title' => 'Call back Ann', 'assignee_user_ids' => [$ana->id]], $t['admin']));

        $rows = collect($this->signedInAs($ana)->getJson('/api/v1/notifications')->assertOk()->json('data'));
        $this->assertSame(['assigned'], $rows->pluck('kind')->unique()->values()->all());
    }
}
