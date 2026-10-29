<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskLinks;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** CX twins on the company and branch boards both remain, linked and synced (Jose, 2026-10-29). */
class TaskLinkSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function board(array $tenant, string $kind, string $id): TaskBoard
    {
        return $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('kind', $kind)->where($kind.'_id', $id)->firstOrFail());
    }

    /** @return array{0: Task, 1: Task, 2: TaskBoard} branch task, company task, company board */
    private function twins(array $tenant): array
    {
        $branch = $this->board($tenant, 'franchise', $tenant['franchise']->id);
        $company = $this->board($tenant, 'company', $tenant['company']->id);

        return $this->inTenant($tenant['org'], function () use ($branch, $company) {
            $w = app(TaskWriter::class);
            $a = $w->create($branch, ['title' => 'Bad review: Ann - cap'], null, (string) Str::uuid());
            $b = $w->create($company, ['title' => 'bad review: ann - cap '], null, (string) Str::uuid());

            return [$a, $b, $company];
        });
    }

    public function test_an_automation_twin_is_linked_when_it_is_made(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$a, $b] = $this->twins($tenant);
        $this->inTenant($tenant['org'], fn () => $this->assertTrue(TaskLinks::exists($a, $b)));

        // A person's task with the same title is not.
        $c = $this->inTenant($tenant['org'], fn () => app(TaskWriter::class)->create($this->board($tenant, 'franchise', $tenant['franchise']->id), ['title' => 'Bad review: Ann - cap'], null));
        $this->inTenant($tenant['org'], fn () => $this->assertFalse(TaskLinks::exists($b, $c)));
    }

    public function test_fields_status_and_owners_sync_both_ways(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$a, $b] = $this->twins($tenant);
        $admin = $tenant['admin'];

        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$a->id}", [
            'title' => 'Called Ann back', 'due_on' => '2026-11-03', 'priority' => 'high', 'status' => 'working_on_it',
            'assignee_user_ids' => [$admin->id],
        ])->assertOk();
        $this->inTenant($tenant['org'], function () use ($b, $admin) {
            $b = $b->fresh();
            $this->assertSame(['Called Ann back', '2026-11-03', 'high', 'working_on_it', $admin->id],
                [$b->title, $b->due_on->toDateString(), $b->priority, $b->status, $b->assignee_user_id]);
        });

        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$b->id}", ['status' => 'done'])->assertOk();
        $this->assertSame('done', $this->inTenant($tenant['org'], fn () => $a->fresh()->status));
    }

    public function test_the_company_board_shows_its_own_twin_not_the_rolled_up_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$a, $b, $company] = $this->twins($tenant);

        $ids = collect($this->signedInAs($tenant['admin'])->getJson("/api/v1/task-boards/{$company->id}")->assertOk()->json('data.tasks'))->pluck('id');
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($a->id, $ids);
    }
}
