<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskListQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Whole-branch review finding 4: `TaskListQuery::crossBoardRows()` never
 * paginated Calendar/Timeline — `page`/`per_page` were accepted, validated,
 * and then ignored, with nothing bounding the result but the organization
 * filter and the (already generous) 400-day window, while every row eager-
 * loads seven relations plus its subtask tree. A cheap GET on a busy
 * organization could materialise tens of thousands of models into one
 * response.
 *
 * Seeds rows with a raw bulk `insert()` rather than the create-task API
 * used elsewhere in this suite — one HTTP-simulated request per task, at
 * `CROSS_BOARD_ROW_CAP + 5` rows, would make this test itself the kind of
 * thing it is testing against.
 */
final class TaskCrossBoardRowCapTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_cross_board_calendar_window_past_the_cap_returns_the_cap_and_reports_the_remainder_as_omitted(): void
    {
        $cap = (new ReflectionClass(TaskListQuery::class))->getConstant('CROSS_BOARD_ROW_CAP');
        $this->assertIsInt($cap, 'CROSS_BOARD_ROW_CAP must exist and be an int for this test to mean anything.');

        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $board = $this->createBoard('Busy board', 'scope');
        app(TenantContext::class)->set($alpha['org']);
        $board = TaskBoard::findOrFail($board->id);
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();

        $today = now()->toDateString();
        $overage = 5;
        $total = $cap + $overage;
        $now = now();
        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'organization_id' => $alpha['org']->id,
                'board_id' => $board->id,
                'group_id' => $group->id,
                'number' => 'CAP-'.$i,
                'title' => "Capped task {$i}",
                'status_option_id' => $status->id,
                'due_on' => $today,
                'position' => $i,
                'version' => 1,
                'detail_version' => 1,
                'created_by' => $alpha['admin']->id,
                'updated_by' => $alpha['admin']->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // Chunked: a single INSERT with 1000+ rows works fine in MySQL, but
        // chunking keeps this robust against any statement-size limit in a
        // real test database configuration.
        foreach (array_chunk($rows, 200) as $chunk) {
            Task::query()->insert($chunk);
        }

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $response = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => ['from' => $from, 'to' => $to]]),
        ]))->assertOk();

        $this->assertCount($cap, $response->json('data'), 'The response must be capped at CROSS_BOARD_ROW_CAP rows, not the full matching set.');
        $this->assertSame($overage, $response->json('meta.omitted.capped'), 'The rows left out by the cap must be reported truthfully, not silently dropped.');
        $this->assertSame(0, $response->json('meta.omitted.undated'));

        // Timeline shares the same unpaginated shape in applyWindow() —
        // proves the cap is not Calendar-only.
        $timeline = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'timeline', 'window' => ['from' => $from, 'to' => $to]]),
        ]))->assertOk();
        $this->assertCount($cap, $timeline->json('data'));
        $this->assertSame($overage, $timeline->json('meta.omitted.capped'));
    }

    public function test_a_cross_board_calendar_window_under_the_cap_reports_nothing_capped(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $board = $this->createBoard('Quiet board', 'scope');
        app(TenantContext::class)->set($alpha['org']);
        $board = TaskBoard::findOrFail($board->id);
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $today = now()->toDateString();
        Task::query()->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $alpha['org']->id,
            'board_id' => $board->id,
            'group_id' => $group->id,
            'number' => 'QUIET-1',
            'title' => 'One quiet task',
            'status_option_id' => $status->id,
            'due_on' => $today,
            'position' => 1,
            'version' => 1,
            'detail_version' => 1,
            'created_by' => $alpha['admin']->id,
            'updated_by' => $alpha['admin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $response = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => ['from' => $from, 'to' => $to]]),
        ]))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(0, $response->json('meta.omitted.capped'));
    }

    private function createBoard(string $name, string $visibility): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', compact('name', 'visibility'))->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return TaskBoard::findOrFail($id);
    }
}
