<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-22: Project Management shows the cards its rules leave on each branch board. */
class JobProjectCardsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_project_group_across_branch_boards_in_the_task_boards_shapes(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $board = TaskBoard::query()->where('franchise_id', $alpha['franchise']->id)->firstOrFail();
            $board->groups()->create(['organization_id' => $board->organization_id, 'name' => 'Project Management', 'position' => 9]);
            $c = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'Ann Client']);
            $job = Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $c->id, 'number' => 'J-9',
                'title' => 'Sweep', 'status' => 'scheduled', 'currency' => 'USD']);
            $w = app(TaskWriter::class);
            $w->create($board, ['title' => 'Paid: J-9', 'group' => 'project management', 'about_type' => 'job', 'about_id' => $job->id], $alpha['admin']);
            $w->create($board, ['title' => 'Elsewhere'], $alpha['admin']);
        });
        $get = fn (string $q = '') => $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management/cards'.$q)->assertOk()->json('data');

        $data = $get();
        $this->assertSame(['Paid: J-9'], array_column($data['tasks'], 'title'), 'only the project group');
        $this->assertCount(1, $data['boards']);
        $this->assertArrayHasKey('builtin_columns', $data['boards'][0]);
        $this->assertSame(['Paid: J-9'], array_column($get('?q=Ann')['tasks'], 'title'), 'search reaches the job it is about');
        $this->assertSame([], $get('?q=nobody')['tasks']);
    }
}
