<?php

namespace Tests\Feature\Dashboard;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskGroup;
use App\Models\TaskStatusOption;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\TasksGlance;
use App\Support\Tasks\BoardMemberRole;
use App\Support\Tasks\BoardVisibility;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TasksGlanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_summarizes_only_accessible_active_tasks_in_the_dashboard_period(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00 UTC');
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $tenant = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'admin@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Alpha Co',
            franchiseName: 'Main',
        );

        $payload = app(TenantContext::class)->run($tenant['organization'], function () use ($tenant): array {
            $board = $this->board($tenant, 'Visible', BoardVisibility::Private);
            TaskBoardMember::create([
                'board_id' => $board->id,
                'user_id' => $tenant['administrator']->id,
                'role' => BoardMemberRole::Owner,
                'is_active' => true,
            ]);
            [$group, $active, $blocked, $completed, $cancelled] = $this->configuration($tenant, $board);

            $this->task($tenant, $board, $group, $active, 'Open today', '2026-08-26');
            $this->task($tenant, $board, $group, $blocked, 'Late and blocked', '2026-07-31');
            $this->task($tenant, $board, $group, $completed, 'Completed', null, '2026-08-20 12:00:00');
            $this->task($tenant, $board, $group, $cancelled, 'Cancelled');

            $hidden = $this->board($tenant, 'Hidden private', BoardVisibility::Private, 1);
            [$hiddenGroup, $hiddenActive] = $this->configuration($tenant, $hidden);
            $this->task($tenant, $hidden, $hiddenGroup, $hiddenActive, 'Must not leak', '2026-07-01');

            return app(TasksGlance::class)->resolve(
                $tenant['administrator'],
                DashboardPeriod::for($tenant['administrator'], 'month'),
                [],
            )->toArray();
        });

        $this->assertSame('bar', $payload['presentation']);
        $this->assertSame([
            'open' => 2,
            'assigned_to_me' => 2,
            'due_in_period' => 1,
            'overdue' => 1,
            'blocked' => 1,
            'completed_in_period' => 1,
        ], $payload['data']['counts']);
        $this->assertSame('Admin', $payload['data']['top_owners'][0]['name']);
        $this->assertSame(2, $payload['data']['top_owners'][0]['open']);
    }

    private function board(array $tenant, string $name, BoardVisibility $visibility, int $position = 0): TaskBoard
    {
        return TaskBoard::factory()->create([
            'organization_id' => $tenant['organization']->id,
            'company_id' => null,
            'franchise_id' => null,
            'name' => $name,
            'visibility' => $visibility,
            'position' => $position,
            'created_by' => $tenant['administrator']->id,
            'updated_by' => $tenant['administrator']->id,
        ]);
    }

    private function configuration(array $tenant, TaskBoard $board): array
    {
        $group = TaskGroup::create(['board_id' => $board->id, 'name' => 'To do', 'position' => 0]);
        $statuses = [];
        foreach ([StatusCategory::Active, StatusCategory::Blocked, StatusCategory::Completed, StatusCategory::Cancelled] as $position => $category) {
            $status = new TaskStatusOption;
            $status->forceFill([
                'organization_id' => $tenant['organization']->id,
                'board_id' => $board->id,
                'name' => $category->value,
                'category' => $category->value,
                'position' => $position,
            ])->save();
            $statuses[] = $status;
        }

        return [$group, ...$statuses];
    }

    private function task(
        array $tenant,
        TaskBoard $board,
        TaskGroup $group,
        TaskStatusOption $status,
        string $title,
        ?string $dueOn = null,
        ?string $completedAt = null,
    ): Task {
        $task = new Task;
        $task->forceFill([
            'organization_id' => $tenant['organization']->id,
            'board_id' => $board->id,
            'group_id' => $group->id,
            'number' => 'TASK-'.str()->random(8),
            'title' => $title,
            'owner_user_id' => $tenant['administrator']->id,
            'status_option_id' => $status->id,
            'due_on' => $dueOn,
            'completed_at' => $completedAt,
            'completed_by' => $completedAt === null ? null : $tenant['administrator']->id,
            'created_by' => $tenant['administrator']->id,
            'updated_by' => $tenant['administrator']->id,
            'position' => Task::query()->where('board_id', $board->id)->count(),
        ])->save();

        return $task;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
