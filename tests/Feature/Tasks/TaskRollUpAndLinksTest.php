<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskLink;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Branch tasks roll up to the company board; linked tasks share Done (Jose, 2026-10-28). */
class TaskRollUpAndLinksTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function board(array $tenant, string $kind, string $id): TaskBoard
    {
        return $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('kind', $kind)->where($kind.'_id', $id)->firstOrFail());
    }

    private function task(array $tenant, TaskBoard $board, string $title, ?string $automation = null): Task
    {
        return $this->inTenant($tenant['org'], function () use ($board, $title, $automation) {
            $task = app(TaskWriter::class)->create($board, ['title' => $title], null);
            if ($automation) {
                $task->forceFill(['created_by_automation_id' => $automation])->save();
            }

            return $task;
        });
    }

    public function test_a_branch_task_shows_on_the_company_board_as_the_same_row_and_not_to_a_sibling(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($tenant, 'Alpha F2');
        $company = $this->board($tenant, 'company', $tenant['company']->id);
        $branch = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $task = $this->task($tenant, $branch, 'Chimney cap');

        $rows = collect($this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$company->id}")->assertOk()->json('data.tasks'));
        $row = $rows->firstWhere('id', $task->id);
        $this->assertSame(['board_id' => $branch->id, 'board_name' => $branch->name, 'group_name' => 'Open Tasks'], $row['rolled_up_from']);

        // The branch board itself does not carry it as rolled up.
        $own = collect($this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$branch->id}")->json('data.tasks'))->firstWhere('id', $task->id);
        $this->assertArrayNotHasKey('rolled_up_from', $own);

        // A person at the sibling branch sees neither the company board's copy nor the task.
        $other = $this->userWithRole($tenant, 'Sibling', ['tasks.view'], null, $sibling);
        $this->signedInAs($other)->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
        $siblingRows = collect($this->signedInAs($other)->getJson('/api/v1/task-boards/'.$this->board($tenant, 'franchise', $sibling->id)->id)->assertOk()->json('data.tasks'));
        $this->assertNull($siblingRows->firstWhere('id', $task->id));
    }

    public function test_a_rolled_up_row_takes_the_company_boards_status_by_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $company = $this->board($tenant, 'company', $tenant['company']->id);
        $task = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Cap');
        [$done, $custom] = $this->inTenant($tenant['org'], fn () => [
            $company->statuses()->where('key', 'done')->first(),
            $company->statuses()->create(['organization_id' => $tenant['org']->id, 'key' => 'waiting_parts', 'name' => 'Waiting parts', 'colour' => '#999999', 'position' => 9, 'is_done' => false]),
        ]);

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$task->id}", ['status_id' => $done->id])
            ->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNotSame($done->id, $this->inTenant($tenant['org'], fn () => $task->fresh()->status_id));

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$task->id}", ['status_id' => $custom->id])
            ->assertStatus(422)->assertJsonPath('errors.status_id.0', 'The board this task is on has no status called Waiting parts.');
    }

    /** Jose 11-17: one box finds a task on any board; the row carries its links. */
    public function test_task_search_spans_visible_boards_and_a_row_carries_its_links(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $a = $this->task($tenant, $this->board($tenant, 'company', $tenant['company']->id), 'Sweep the Gilbert chimney');
        $b = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Call Gilbert back');
        $admin = $tenant['admin'];

        $found = $this->signedInAs($admin)->getJson('/api/v1/task-search?q=gilbert')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($found, 'id'));
        $this->assertNotEmpty(array_filter(array_column($found, 'board_name')));
        $this->signedInAs($admin)->getJson('/api/v1/task-search?q=g')->assertStatus(422);
        $this->assertSame([], $this->signedInAs($admin)->getJson('/api/v1/task-search?q=%25%25')->assertOk()->json('data'));

        $this->signedInAs($admin)->postJson("/api/v1/tasks/{$a->id}/links", ['linked_task_id' => $b->id])->assertCreated();
        $row = $this->signedInAs($admin)->getJson("/api/v1/tasks/{$a->id}")->assertOk()->json('data');
        $this->assertSame([$b->id], array_column($row['links'], 'id'));
    }

    public function test_linked_tasks_show_each_other_and_share_done_and_reopen(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $a = $this->task($tenant, $this->board($tenant, 'company', $tenant['company']->id), 'Bad review: x');
        $b = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Bad review: x');
        $admin = $tenant['admin'];

        $this->signedInAs($admin)->postJson("/api/v1/tasks/{$a->id}/links", ['linked_task_id' => $b->id])->assertCreated();
        $this->signedInAs($admin)->postJson("/api/v1/tasks/{$a->id}/links", ['linked_task_id' => $b->id])->assertOk();
        $this->assertSame([$a->id], collect($this->signedInAs($admin)->getJson("/api/v1/tasks/{$b->id}/links")->assertOk()->json('data'))->pluck('id')->all());

        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$b->id}", ['status' => 'done'])->assertOk();
        $this->assertSame('done', $this->inTenant($tenant['org'], fn () => $a->fresh()->status));

        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$a->id}", ['status' => 'working_on_it'])->assertOk();
        $this->assertSame('working_on_it', $this->inTenant($tenant['org'], fn () => $b->fresh()->status));

        $this->signedInAs($admin)->deleteJson("/api/v1/tasks/{$b->id}/links/{$a->id}")->assertNoContent();
        $this->assertSame([], $this->signedInAs($admin)->getJson("/api/v1/tasks/{$a->id}/links")->json('data'));
    }

    public function test_linking_a_task_you_cannot_see_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($tenant, 'Alpha F2');
        $mine = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Mine');
        $theirs = $this->task($tenant, $this->board($tenant, 'franchise', $sibling->id), 'Theirs');
        $user = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit'], null, $tenant['franchise']);

        $this->signedInAs($user)->postJson("/api/v1/tasks/{$mine->id}/links", ['linked_task_id' => $theirs->id])->assertStatus(422);
    }

    public function test_the_duplicate_command_links_only_with_apply_and_never_deletes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $rule = (string) Str::uuid();
        $company = $this->task($tenant, $this->board($tenant, 'company', $tenant['company']->id), 'Complaint: Ann +15551234567 - Cap', $rule);
        $branch = $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'complaint: ann +15551234567 - cap ', $rule);
        $this->task($tenant, $this->board($tenant, 'franchise', $tenant['franchise']->id), 'Complaint: Ann +15551234567 - Cap'); // by a person: not a duplicate
        $count = fn () => $this->inTenant($tenant['org'], fn () => [TaskLink::query()->count(), Task::query()->count()]);

        $this->artisan('ezbizi:link-duplicate-tasks', ['organization_id' => $tenant['org']->id])
            ->expectsOutputToContain('[dry run, nothing saved] pairs: 1, would link: 1')->assertSuccessful();
        $this->assertSame([0, 3], $count());

        $this->artisan('ezbizi:link-duplicate-tasks', ['organization_id' => $tenant['org']->id, '--apply' => true])
            ->expectsOutputToContain('pairs: 1, linked: 1')->assertSuccessful();
        $this->assertSame([1, 3], $count());
        $this->assertTrue($this->inTenant($tenant['org'], fn () => TaskLink::query()->where('task_id', $company->id)->where('linked_task_id', $branch->id)->exists()));

        $this->artisan('ezbizi:link-duplicate-tasks', ['organization_id' => $tenant['org']->id, '--apply' => true])
            ->expectsOutputToContain('linked: 0')->assertSuccessful();
    }
}
