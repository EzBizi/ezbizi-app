<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskWindowedRowsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    private string $statusId;

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
            'name' => 'Scheduling', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $this->statusId = $this->board->statusOptions()->active()
            ->where('category', StatusCategory::NotStarted)->firstOrFail()->id;
    }

    public function test_calendar_window_returns_tasks_whose_due_date_falls_inside_it_and_no_task_outside(): void
    {
        $inside = $this->createTask('Inside window', null, '2026-09-10');
        $this->createTask('Before window', null, '2026-08-01');
        $this->createTask('After window', null, '2026-10-01');
        $this->createTask('No date at all', null, null);

        $configuration = $this->configuration('calendar', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $response = $this->getJson($this->rowsUrl($configuration))->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->values()->all();
        $this->assertSame([$inside->id], $ids);
        $this->assertSame(1, $response->json('meta.omitted.undated'));
    }

    public function test_timeline_window_returns_tasks_whose_span_intersects_the_range(): void
    {
        $spanning = $this->createTask('Starts before, ends inside', '2026-08-20', '2026-09-05');
        $inside = $this->createTask('Fully inside', '2026-09-10', '2026-09-15');
        $this->createTask('Fully after', '2026-10-01', '2026-10-05');
        $this->createTask('No dates at all', null, null);

        $configuration = $this->configuration('timeline', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $response = $this->getJson($this->rowsUrl($configuration))->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$spanning->id, $inside->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->assertSame(1, $response->json('meta.omitted.undated'));
    }

    public function test_kanban_window_returns_the_complete_accessible_set_per_named_bucket_rather_than_a_page(): void
    {
        $active = $this->board->statusOptions()->active()->where('category', StatusCategory::Active)->firstOrFail();
        $notStarted = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $blocked = $this->board->statusOptions()->active()->where('category', StatusCategory::Blocked)->firstOrFail();

        $first = $this->createTask('One', null, null, $notStarted->id);
        $second = $this->createTask('Two', null, null, $active->id);
        $this->createTask('Three', null, null, $blocked->id);

        $configuration = $this->configuration('kanban', [
            'bucket_field' => 'status', 'buckets' => [$notStarted->id, $active->id],
        ]);
        $response = $this->getJson($this->rowsUrl($configuration, ['per_page' => 1]))->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$first->id, $second->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_meta_summary_describes_the_complete_filtered_accessible_result_not_the_returned_slice(): void
    {
        foreach (range(1, 5) as $day) {
            $this->createTask("Task {$day}", null, sprintf('2026-09-%02d', $day));
        }

        $configuration = $this->configuration('calendar', ['from' => '2026-09-01', 'to' => '2026-09-02']);
        $response = $this->getJson($this->rowsUrl($configuration))->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.summary.total'));
    }

    public function test_meta_omitted_undated_counts_tasks_excluded_for_having_no_date(): void
    {
        $this->createTask('Dated', null, '2026-09-15');
        $this->createTask('Undated one', null, null);
        $this->createTask('Undated two', null, null);

        $configuration = $this->configuration('calendar', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $response = $this->getJson($this->rowsUrl($configuration))->assertOk();

        $this->assertSame(2, $response->json('meta.omitted.undated'));
    }

    public function test_requests_differing_only_in_window_produce_different_etags(): void
    {
        $this->createTask('Anchored', null, '2026-09-15');

        $first = $this->configuration('calendar', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $second = $this->configuration('calendar', ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $etagOne = $this->getJson($this->rowsUrl($first))->assertOk()->headers->get('ETag');
        $etagTwo = $this->getJson($this->rowsUrl($second))->assertOk()->headers->get('ETag');

        $this->assertNotNull($etagOne);
        $this->assertNotNull($etagTwo);
        $this->assertNotSame($etagOne, $etagTwo);
    }

    public function test_revoked_private_membership_stops_returning_windowed_rows_mid_session(): void
    {
        $privateId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Private scheduling', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $private = TaskBoard::findOrFail($privateId);
        $worker = $this->userWithRole($this->alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $this->enterTenant();
        $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated();
        $this->enterTenant();
        $status = $private->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $private->groups()->active()->firstOrFail();
        $this->postJson("/api/v1/tasks/boards/{$private->id}/tasks", [
            'title' => 'Secret windowed task', 'due_on' => '2026-09-15',
            'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated();

        $this->enterTenant();
        $worker->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->signedInAs($worker);
        $configuration = $this->configuration('calendar', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $url = "/api/v1/tasks/boards/{$private->id}/rows?".http_build_query(['configuration' => json_encode($configuration)]);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1);

        $this->enterTenant();
        TaskBoardMember::where('board_id', $private->id)->where('user_id', $worker->id)
            ->update(['is_active' => false, 'archived_at' => now()]);

        $this->getJson($url)->assertNotFound();
    }

    private function createTask(string $title, ?string $startOn, ?string $dueOn, ?string $statusId = null): Task
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title,
            'group_id' => $group->id,
            'status_option_id' => $statusId ?? $this->statusId,
            'start_on' => $startOn,
            'due_on' => $dueOn,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function configuration(string $presentation, array $window): array
    {
        return [
            'schema' => 2, 'presentation' => $presentation, 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => [], 'column_widths' => (object) [],
            'group_by' => null, 'search' => '', 'window' => $window,
        ];
    }

    private function rowsUrl(array $configuration, array $extra = []): string
    {
        return "/api/v1/tasks/boards/{$this->board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration), ...$extra,
        ]);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }
}
