<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskView;
use App\Support\Tasks\TaskViewCodec;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskPresentationCodecTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->actingAs($this->alpha['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Operations', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_schema_one_stored_view_upgrades_to_schema_two_on_read_without_rewriting_storage(): void
    {
        $legacy = [
            'schema' => 1,
            'presentation' => 'table',
            'filters' => [['field' => 'task', 'operator' => 'contains', 'value' => 'door']],
            'sorts' => [['field' => 'due_on', 'direction' => 'asc']],
            'hidden_columns' => ['owner'],
            'column_order' => ['task', 'status', 'due_on'],
            'column_widths' => (object) ['task' => 250],
            'group_by' => 'status',
            'search' => 'door',
        ];
        $view = new TaskView;
        $view->forceFill([
            'board_id' => $this->board->id,
            'owner_user_id' => null,
            'visibility' => 'shared',
            'name' => 'Legacy shared view',
            'is_locked' => false,
            'is_permanent_main' => false,
            'configuration' => $legacy,
            'position' => 5,
        ])->save();

        $before = DB::table('task_views')->find($view->id);
        $beforeConfiguration = json_decode($before->configuration, true);
        $this->assertSame(1, $beforeConfiguration['schema']);
        $this->assertArrayNotHasKey('window', $beforeConfiguration);

        $decoded = app(TaskViewCodec::class)->decode($this->board, $view->fresh()->configuration);

        $this->assertSame(2, $decoded['schema']);
        $this->assertSame('table', $decoded['presentation']);
        $this->assertNull($decoded['window']);
        $this->assertSame('status', $decoded['group_by']);
        $this->assertSame('door', $decoded['search']);
        $this->assertSame(['owner'], $decoded['hidden_columns']);
        $this->assertSame([['field' => 'due_on', 'direction' => 'asc']], $decoded['sorts']);
        $this->assertSame('task', $decoded['filters'][0]['field']);
        $this->assertSame('contains', $decoded['filters'][0]['operator']);
        $this->assertSame('door', $decoded['filters'][0]['value']);

        $after = DB::table('task_views')->find($view->id);
        $afterConfiguration = json_decode($after->configuration, true);
        $this->assertSame(1, $afterConfiguration['schema'], 'Decoding on read must never rewrite the stored schema 1 row.');
        $this->assertArrayNotHasKey('window', $afterConfiguration);
        $this->assertSame($before->configuration, $after->configuration);
    }

    public function test_unknown_presentation_is_refused(): void
    {
        $codec = app(TaskViewCodec::class);
        $input = $this->configuration(['presentation' => 'chart']);

        $this->assertRefused(fn () => $codec->decode($this->board, $input), 'configuration.presentation');
    }

    public function test_a_window_belonging_to_a_different_presentation_is_refused(): void
    {
        $codec = app(TaskViewCodec::class);
        $input = $this->configuration([
            'presentation' => 'kanban',
            'window' => ['from' => '2026-09-01', 'to' => '2026-09-10'],
        ]);

        $this->assertRefused(fn () => $codec->decode($this->board, $input), 'configuration.window');
    }

    public function test_calendar_and_timeline_windows_are_bounded_to_400_days(): void
    {
        $codec = app(TaskViewCodec::class);

        // Exactly 400 days is allowed.
        $ok = $this->configuration([
            'presentation' => 'calendar',
            'window' => ['from' => '2026-01-01', 'to' => '2027-02-05'],
        ]);
        $decoded = $codec->decode($this->board, $ok);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2027-02-05'], $decoded['window']);

        // 401 days is refused.
        $tooWide = $this->configuration([
            'presentation' => 'timeline',
            'window' => ['from' => '2026-01-01', 'to' => '2027-02-06'],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $tooWide), 'configuration.window');

        // "to" before "from" is refused regardless of span.
        $inverted = $this->configuration([
            'presentation' => 'calendar',
            'window' => ['from' => '2026-09-10', 'to' => '2026-09-01'],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $inverted), 'configuration.window');
    }

    public function test_kanban_more_than_fifty_buckets_is_refused(): void
    {
        $codec = app(TaskViewCodec::class);
        $status = $this->board->statusOptions()->active()->firstOrFail();

        $ok = $this->configuration([
            'presentation' => 'kanban',
            'window' => ['bucket_field' => 'status', 'buckets' => [$status->id]],
        ]);
        $decoded = $codec->decode($this->board, $ok);
        $this->assertSame('status', $decoded['window']['bucket_field']);

        $exactlyFifty = $this->configuration([
            'presentation' => 'kanban',
            'window' => [
                'bucket_field' => 'status',
                'buckets' => array_map(fn () => (string) Str::uuid7(), range(1, 50)),
            ],
        ]);
        $decodedFifty = $codec->decode($this->board, $exactlyFifty);
        $this->assertCount(50, $decodedFifty['window']['buckets']);

        $tooMany = $this->configuration([
            'presentation' => 'kanban',
            'window' => [
                'bucket_field' => 'status',
                'buckets' => array_map(fn () => (string) Str::uuid7(), range(1, 51)),
            ],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $tooMany), 'configuration.window');
    }

    public function test_kanban_bucket_field_is_refused_before_it_reaches_sql(): void
    {
        $codec = app(TaskViewCodec::class);

        // Foreign: a custom column that belongs to a different board.
        $beta = $this->provisionTenant('Beta');
        app(TenantContext::class)->set($beta['org']);
        $this->actingAs($beta['admin']->fresh());
        $betaBoardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Beta board', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($beta['org']);
        $betaBoard = TaskBoard::findOrFail($betaBoardId);
        $foreignColumnId = $this->postJson("/api/v1/tasks/boards/{$betaBoard->id}/columns", [
            'name' => 'Region', 'type' => 'number',
            'expected_structure_version' => $betaBoard->structure_version,
        ])->assertCreated()->json('data.id');

        app(TenantContext::class)->set($this->alpha['org']);
        $this->actingAs($this->alpha['admin']->fresh());
        $foreign = $this->configuration([
            'presentation' => 'kanban',
            'window' => ['bucket_field' => 'custom:'.$foreignColumnId, 'buckets' => []],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $foreign), 'configuration.window.bucket_field');

        // Archived: a real column on this board, but archived.
        $columnId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => 'Region', 'type' => 'number',
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$columnId}/archive", [
            'expected_structure_version' => $this->board->structure_version,
        ])->assertOk();
        $this->enterTenant();
        $this->board = $this->board->fresh();
        $archived = $this->configuration([
            'presentation' => 'kanban',
            'window' => ['bucket_field' => 'custom:'.$columnId, 'buckets' => []],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $archived), 'configuration.window.bucket_field');

        // Disallowed type: kanban follows the same set Phase 1 permits for group_by,
        // which excludes custom People, Label, and File columns.
        $labelColumnId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => 'Tags', 'type' => 'label',
            'expected_structure_version' => $this->board->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();
        $disallowed = $this->configuration([
            'presentation' => 'kanban',
            'window' => ['bucket_field' => 'custom:'.$labelColumnId, 'buckets' => []],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $disallowed), 'configuration.window.bucket_field');
    }

    public function test_workload_window_requires_a_valid_unit(): void
    {
        $codec = app(TaskViewCodec::class);
        $day = $this->configuration([
            'presentation' => 'workload',
            'window' => ['from' => '2026-09-01', 'to' => '2026-09-14', 'unit' => 'day'],
        ]);
        $decodedDay = $codec->decode($this->board, $day);
        $this->assertSame('day', $decodedDay['window']['unit']);

        $week = $this->configuration([
            'presentation' => 'workload',
            'window' => ['from' => '2026-09-01', 'to' => '2026-09-14', 'unit' => 'week'],
        ]);
        $decoded = $codec->decode($this->board, $week);
        $this->assertSame('week', $decoded['window']['unit']);

        $invalid = $this->configuration([
            'presentation' => 'workload',
            'window' => ['from' => '2026-09-01', 'to' => '2026-09-14', 'unit' => 'month'],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $invalid), 'configuration.window');
    }

    public function test_workload_window_is_also_bounded_to_400_days(): void
    {
        $codec = app(TaskViewCodec::class);

        // Exactly 400 days is allowed.
        $ok = $this->configuration([
            'presentation' => 'workload',
            'window' => ['from' => '2026-01-01', 'to' => '2027-02-05', 'unit' => 'day'],
        ]);
        $decoded = $codec->decode($this->board, $ok);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2027-02-05', 'unit' => 'day'], $decoded['window']);

        // 401 days is refused, same bound as calendar/timeline.
        $tooWide = $this->configuration([
            'presentation' => 'workload',
            'window' => ['from' => '2026-01-01', 'to' => '2027-02-06', 'unit' => 'day'],
        ]);
        $this->assertRefused(fn () => $codec->decode($this->board, $tooWide), 'configuration.window');
    }

    public function test_permanent_main_view_cannot_change_presentation(): void
    {
        $codec = app(TaskViewCodec::class);
        $kanban = $this->configuration(['presentation' => 'kanban', 'window' => null]);

        $this->assertRefused(fn () => $codec->decode($this->board, $kanban, true), 'configuration.presentation');

        // And through the real write path, against the board's actual permanent Main view.
        $mainId = $this->board->views()->where('is_permanent_main', true)->value('id');
        $this->patchJson("/api/v1/tasks/boards/{$this->board->id}/views/{$mainId}", [
            'expected_view_version' => 1,
            'configuration' => $this->configuration(['presentation' => 'kanban', 'window' => null]),
        ])->assertUnprocessable()->assertJsonValidationErrors('configuration.presentation');
    }

    public function test_schema_two_payload_still_carries_every_schema_one_field_at_its_schema_one_meaning(): void
    {
        $codec = app(TaskViewCodec::class);
        $status = $this->board->statusOptions()->active()->firstOrFail();
        $input = $this->configuration([
            'presentation' => 'table',
            'filters' => [['field' => 'status', 'operator' => 'in', 'value' => [$status->id]]],
            'sorts' => [['field' => 'due_on', 'direction' => 'desc']],
            'hidden_columns' => ['priority'],
            'column_order' => ['task', 'owner', 'status'],
            'column_widths' => (object) ['owner' => 300],
            'group_by' => 'status',
            'search' => 'north',
            'window' => ['page' => 2, 'per_page' => 25],
        ]);

        $decoded = $codec->decode($this->board, $input);

        $this->assertSame(2, $decoded['schema']);
        $this->assertSame('table', $decoded['presentation']);
        $this->assertSame('status', $decoded['filters'][0]['field']);
        $this->assertSame('in', $decoded['filters'][0]['operator']);
        $this->assertSame([$status->id], $decoded['filters'][0]['value']);
        $this->assertSame([['field' => 'due_on', 'direction' => 'desc']], $decoded['sorts']);
        $this->assertSame(['priority'], $decoded['hidden_columns']);
        $this->assertSame(['owner', 'status'], $decoded['column_order']);
        $this->assertSame(300, $decoded['column_widths']->owner ?? null);
        $this->assertSame('status', $decoded['group_by']);
        $this->assertSame('north', $decoded['search']);
        $this->assertSame(['page' => 2, 'per_page' => 25], $decoded['window']);
    }

    public function test_default_main_view_never_resaved_still_returns_the_upgraded_shape_over_the_api(): void
    {
        // The board's permanent Main view was seeded by TaskBoardWriter directly from
        // TaskViewCodec::defaults(), bypassing decode() entirely, and setUp() never touches
        // it. Its stored bytes must still be schema 1.
        $mainId = $this->board->views()->where('is_permanent_main', true)->value('id');
        $stored = DB::table('task_views')->find($mainId);
        $storedConfiguration = json_decode($stored->configuration, true);
        $this->assertSame(1, $storedConfiguration['schema']);
        $this->assertArrayNotHasKey('window', $storedConfiguration, 'A schema 1 row never carries a window key in storage.');

        $response = $this->getJson("/api/v1/tasks/boards/{$this->board->id}/views")
            ->assertOk();
        $main = collect($response->json('data'))->firstWhere('id', $mainId);
        $this->assertNotNull($main, 'The permanent Main view must appear in the listing.');
        $this->assertArrayHasKey('presentation', $main['configuration']);
        $this->assertArrayHasKey('window', $main['configuration']);
        $this->assertSame('table', $main['configuration']['presentation']);
        $this->assertNull($main['configuration']['window']);
        $this->assertSame(2, $main['configuration']['schema']);

        // Still never rewritten in storage by the mere read.
        $after = DB::table('task_views')->find($mainId);
        $this->assertSame($stored->configuration, $after->configuration);
    }

    private function assertRefused(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail("Expected a ValidationException on {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function configuration(array $replace = []): array
    {
        return array_replace([
            'schema' => 2, 'presentation' => 'table', 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => ['task', 'owner', 'status', 'priority', 'start_on', 'due_on'],
            'column_widths' => (object) [], 'group_by' => null, 'search' => '', 'window' => null,
        ], $replace);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }
}
