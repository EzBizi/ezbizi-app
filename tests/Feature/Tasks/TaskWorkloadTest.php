<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Workload presentation: per-owner task counts per day or week, aggregated in
 * SQL over a bounded window (Task 7).
 *
 * COUNTS TASKS, never hours — there is no effort field or capacity notion
 * anywhere in this suite.
 */
class TaskWorkloadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    private string $notStartedStatusId;

    private string $completedStatusId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->alpha['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($this->alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Workload board', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $this->notStartedStatusId = $this->board->statusOptions()->active()
            ->where('category', StatusCategory::NotStarted)->firstOrFail()->id;
        $this->completedStatusId = $this->board->statusOptions()->active()
            ->where('category', StatusCategory::Completed)->firstOrFail()->id;
    }

    public function test_a_task_with_both_dates_counts_on_every_unit_its_span_covers(): void
    {
        $admin = $this->alpha['admin']->fresh();
        $this->createTask('Spans five days', '2026-09-10', '2026-09-14', $admin->id);

        $response = $this->getJson($this->rowsUrl($this->configuration('day', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('owner_id', $admin->id);
        $this->assertNotNull($row);
        $dates = collect($row['units'])->pluck('date')->sort()->values()->all();
        $this->assertSame(
            ['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14'],
            $dates
        );
        $this->assertTrue(collect($row['units'])->every(fn ($unit) => $unit['count'] === 1));
    }

    public function test_a_task_with_only_a_due_date_counts_on_that_date_alone(): void
    {
        $admin = $this->alpha['admin']->fresh();
        $this->createTask('Due date only', null, '2026-09-20', $admin->id);

        $response = $this->getJson($this->rowsUrl($this->configuration('day', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('owner_id', $admin->id);
        $this->assertNotNull($row);
        $this->assertSame([['date' => '2026-09-20', 'count' => 1]], $row['units']);
    }

    public function test_archived_and_completed_tasks_are_excluded(): void
    {
        $admin = $this->alpha['admin']->fresh();
        $this->createTask('Counted', null, '2026-09-05', $admin->id);
        $archived = $this->createTask('Archived away', null, '2026-09-06', $admin->id);
        $completed = $this->createTask('Marked done', null, '2026-09-07', $admin->id);

        $this->postJson("/api/v1/tasks/{$archived->id}/archive", [
            'expected_version' => $archived->version,
        ])->assertOk();
        $this->enterTenant();
        $this->patchJson("/api/v1/tasks/{$completed->id}", [
            'status_option_id' => $this->completedStatusId,
            'expected_version' => $completed->version,
        ])->assertOk();
        $this->enterTenant();

        $response = $this->getJson($this->rowsUrl($this->configuration('day', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('owner_id', $admin->id);
        $this->assertNotNull($row);
        $this->assertSame([['date' => '2026-09-05', 'count' => 1]], $row['units']);
    }

    public function test_unowned_tasks_appear_in_an_explicit_owner_id_null_row(): void
    {
        $this->createTask('Nobody has this yet', '2026-09-08', '2026-09-10', null);

        $response = $this->getJson($this->rowsUrl($this->configuration('day', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->first(fn ($r) => $r['owner_id'] === null);
        $this->assertNotNull($row, 'Expected an explicit owner_id: null row for the unowned task.');
        $this->assertNull($row['owner_name']);
        $dates = collect($row['units'])->pluck('date')->sort()->values()->all();
        $this->assertSame(['2026-09-08', '2026-09-09', '2026-09-10'], $dates);

        // A count of distinct unowned tasks, not day-instances — this one task
        // spans three days and must still read as one omitted task, not three.
        $this->assertSame(1, $response->json('meta.omitted.unowned'));
    }

    public function test_week_unit_buckets_by_the_organizations_week_start(): void
    {
        $this->inTenant($this->alpha['org'], fn () => app(SettingsResolver::class)
            ->set('week_starts_on', $this->alpha['org'], 'monday'));

        $admin = $this->alpha['admin']->fresh();
        // Thursday 2026-09-03. Its Monday-start week begins 2026-08-31, not
        // the Sunday-start 2026-08-30 the implementation must not assume.
        $this->createTask('Mid-week due date', null, '2026-09-03', $admin->id);

        $response = $this->getJson($this->rowsUrl($this->configuration('week', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('owner_id', $admin->id);
        $this->assertNotNull($row);
        $this->assertSame([['date' => '2026-08-31', 'count' => 1]], $row['units']);
    }

    public function test_a_task_spanning_several_days_inside_one_week_counts_once_for_that_week(): void
    {
        $admin = $this->alpha['admin']->fresh();
        // Tue 2026-09-08 through Thu 2026-09-10, all inside the Sunday-start
        // week of 2026-09-06 through 2026-09-12 (default week_starts_on is
        // sunday — not overridden here). A regression that counted per DAY
        // instead of per UNIT would report count: 3 for this single task;
        // the both-dates unit=day tests cannot catch that, because with
        // unit=day every day is already its own distinct bucket.
        $this->createTask('Three days, one week', '2026-09-08', '2026-09-10', $admin->id);

        $response = $this->getJson($this->rowsUrl($this->configuration('week', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('owner_id', $admin->id);
        $this->assertNotNull($row);
        $this->assertSame([['date' => '2026-09-06', 'count' => 1]], $row['units']);
    }

    public function test_a_person_with_no_tasks_in_the_window_does_not_appear_as_a_phantom_zero_row(): void
    {
        $admin = $this->alpha['admin']->fresh();
        $idle = $this->userWithRole($this->alpha, 'Idle worker', ['tasks.view', 'tasks.edit']);
        $this->createTask('Only the admin has this one', null, '2026-09-12', $admin->id);

        $response = $this->getJson($this->rowsUrl($this->configuration('day', '2026-09-01', '2026-09-30')))
            ->assertOk();

        $ownerIds = collect($response->json('data'))->pluck('owner_id')->values()->all();
        $this->assertSame([$admin->id], $ownerIds);
        $this->assertNotContains($idle->id, $ownerIds);
    }

    private function createTask(string $title, ?string $startOn, ?string $dueOn, ?string $ownerId, ?string $statusId = null): Task
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title,
            'group_id' => $group->id,
            'status_option_id' => $statusId ?? $this->notStartedStatusId,
            'start_on' => $startOn,
            'due_on' => $dueOn,
            'owner_user_id' => $ownerId,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function configuration(string $unit, string $from, string $to): array
    {
        return [
            'schema' => 2, 'presentation' => 'workload', 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => [], 'column_widths' => (object) [],
            'group_by' => null, 'search' => '',
            'window' => ['from' => $from, 'to' => $to, 'unit' => $unit],
        ];
    }

    private function rowsUrl(array $configuration): string
    {
        return "/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration),
        ]);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }
}
