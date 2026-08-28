<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnValue;
use App\Models\TaskDependency;
use App\Models\TaskRecurrenceOccurrence;
use App\Models\TaskUpdate;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskRecurrenceWorkerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Operations', 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    // ── after_completion ─────────────────────────────────────────

    public function test_after_completion_creates_exactly_one_instance_dated_from_completion_and_nothing_while_open(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Service the truck');
        $this->declareRecurrence($task, 'after_completion', 3, 'day');

        // Merely editing the task (staying open) must create nothing.
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'title' => 'Service the truck (updated)',
        ])->assertOk();
        $this->enterTenant();
        $this->assertSame(0, TaskRecurrenceOccurrence::count());

        $before = $this->board->tasks()->count();
        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();

        $this->enterTenant();
        $this->assertSame($before + 1, $this->board->tasks()->count());
        $occurrence = TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('2026-09-04', $occurrence->occurrence_date->format('Y-m-d'));
        $this->assertNotNull($occurrence->created_task_id);
        $instance = Task::findOrFail($occurrence->created_task_id);
        $this->assertNotSame($task->number, $instance->number);
        $this->assertSame('2026-09-04', $instance->due_on?->format('Y-m-d'));

        // Exactly one occurrence is ever pending for this series at a time.
        $this->assertSame(1, TaskRecurrenceOccurrence::where('task_id', $task->id)->count());
    }

    // ── on_schedule ───────────────────────────────────────────────

    public function test_on_schedule_creates_instances_on_the_calendar_whether_or_not_the_previous_was_finished(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Weekly inspection', startOn: '2026-09-01');
        $this->declareRecurrence($task, 'on_schedule', 1, 'week');

        // Three weeks pass. Nothing was ever completed.
        Carbon::setTestNow('2026-09-22 12:00:00');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $dates = TaskRecurrenceOccurrence::where('task_id', $task->id)
            ->orderBy('occurrence_date')->pluck('occurrence_date')
            ->map(fn ($d) => $d->format('Y-m-d'))->all();
        $this->assertSame(['2026-09-08', '2026-09-15', '2026-09-22'], $dates);
        $this->assertSame(3, Task::where('board_id', $this->board->id)
            ->where('title', 'Weekly inspection')->where('id', '!=', $task->id)->count());
    }

    public function test_retried_run_creates_no_duplicate(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Retry me', startOn: '2026-09-01');
        $this->declareRecurrence($task, 'on_schedule', 1, 'day');
        Carbon::setTestNow('2026-09-02 12:00:00');

        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(1, TaskRecurrenceOccurrence::where('task_id', $task->id)->count());
        $this->assertSame(1, Task::where('board_id', $this->board->id)
            ->where('title', 'Retry me')->where('id', '!=', $task->id)->count());
    }

    public function test_overlapping_run_creates_no_duplicate(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Overlap me', startOn: '2026-09-01');
        $this->declareRecurrence($task, 'on_schedule', 1, 'day');
        Carbon::setTestNow('2026-09-02 12:00:00');

        // Simulate a second, overlapping worker run having already won the
        // race for this occurrence date before this run reaches it.
        $this->enterTenant();
        $winner = $this->board->tasks()->forceCreate($this->rawTaskAttributes('Overlap me (winner)'));
        TaskRecurrenceOccurrence::create([
            'board_id' => $this->board->id,
            'task_id' => $task->id,
            'occurrence_date' => '2026-09-02',
            'created_task_id' => $winner->id,
            'created_at' => now(),
        ]);

        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(1, TaskRecurrenceOccurrence::where('task_id', $task->id)->count());
        $this->assertSame($winner->id, TaskRecurrenceOccurrence::where('task_id', $task->id)->value('created_task_id'));
    }

    // ── timezone ──────────────────────────────────────────────────

    public function test_day_boundary_resolves_in_the_boards_place_timezone_not_utc(): void
    {
        // Organization timezone defaults to Etc/GMT+5 (UTC-5, no DST). This
        // instant is 2026-09-01 02:00 UTC, which is still 2026-08-31 in the
        // board's place. A UTC-dated completion would land one day later.
        Carbon::setTestNow('2026-09-01 02:00:00');
        $task = $this->createTask('Boundary check');
        $this->declareRecurrence($task, 'after_completion', 1, 'day');

        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();

        $this->enterTenant();
        $occurrence = TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('2026-09-01', $occurrence->occurrence_date->format('Y-m-d'));
    }

    // ── until / count exhaustion ─────────────────────────────────

    public function test_count_exhaustion_stops_the_series_and_emits_recurrence_completed(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Bounded by count', startOn: '2026-09-01');
        $this->declareRecurrenceWithBounds($task, 'on_schedule', 1, 'day', count: 2);

        Carbon::setTestNow('2026-09-10 12:00:00');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $created = TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNotNull('created_task_id')->count();
        $this->assertSame(2, $created);
        $terminal = TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNull('created_task_id')->first();
        $this->assertNotNull($terminal);
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.recurrence_completed')
            ->where('subject_id', $task->id)->count());

        // A further run must not re-emit or create anything further.
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();
        $this->enterTenant();
        $this->assertSame(2, TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNotNull('created_task_id')->count());
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.recurrence_completed')
            ->where('subject_id', $task->id)->count());
    }

    public function test_until_exhaustion_stops_the_series_and_emits_recurrence_completed(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Bounded by until', startOn: '2026-09-01');
        $this->declareRecurrenceWithBounds($task, 'on_schedule', 1, 'day', until: '2026-09-02');

        Carbon::setTestNow('2026-09-10 12:00:00');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $created = TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNotNull('created_task_id')->count();
        $this->assertSame(1, $created);
        $this->assertNotNull(TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNull('created_task_id')->first());
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.recurrence_completed')
            ->where('subject_id', $task->id)->count());
    }

    // ── field copy list ───────────────────────────────────────────

    public function test_created_instance_copies_the_allowed_fields_and_not_the_excluded_ones(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $column = $this->createColumn('Reference');
        $collaborator = $this->userWithRole($this->tenant, 'Collaborator', ['tasks.view', 'tasks.edit']);
        $group = $this->board->groups()->active()->firstOrFail();
        $priority = $this->board->priorityOptions()->active()->where('rank', 'high')->firstOrFail();

        $task = $this->createTask('Full copy', description: '<p>Do the thing</p>');
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version,
            'owner_user_id' => $collaborator->id,
            'collaborator_user_ids' => [$collaborator->id],
            'priority_option_id' => $priority->id,
        ])->assertOk();
        $this->enterTenant();
        $task = Task::findOrFail($task->id);
        $this->putJson("/api/v1/tasks/{$task->id}/columns/{$column->id}", [
            'expected_version' => $task->version, 'value' => 'REF-9',
        ])->assertOk();

        $this->enterTenant();
        $task = Task::findOrFail($task->id);
        $blocker = $this->createTask('Blocker');
        $this->postJson("/api/v1/tasks/{$task->id}/dependencies", ['blocker_task_id' => $blocker->id])->assertCreated();
        $this->postJson("/api/v1/tasks/{$task->id}/updates", [
            'body' => '<p>Progress note</p>', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid7(),
        ])->assertCreated();

        $this->enterTenant();
        $task = Task::findOrFail($task->id);
        $this->declareRecurrence($task, 'after_completion', 1, 'day');
        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();

        $this->enterTenant();
        $occurrence = TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail();
        $instance = Task::findOrFail($occurrence->created_task_id);

        // Copied.
        $this->assertSame('Full copy', $instance->title);
        $this->assertSame('<p>Do the thing</p>', $instance->description);
        $this->assertSame($collaborator->id, $instance->owner_user_id);
        $this->assertSame([$collaborator->id], $instance->collaborators()->pluck('user_id')->all());
        $this->assertSame($group->id, $instance->group_id);
        $this->assertSame($priority->id, $instance->priority_option_id);
        $this->assertSame('REF-9', TaskColumnValue::where('task_id', $instance->id)
            ->where('column_definition_id', $column->id)->value('text_value'));

        // Not copied.
        $this->assertSame(0, TaskUpdate::where('task_id', $instance->id)->count());
        $this->assertSame(0, TaskDependency::where('blocked_task_id', $instance->id)->count());
        $this->assertSame(0, TaskDependency::where('blocker_task_id', $instance->id)->count());
        $this->assertNull($instance->completed_at);
        $this->assertNotSame(StatusCategory::Completed, $instance->statusOption()->firstOrFail()->category);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function createTask(string $title, ?string $startOn = null, ?string $description = null): Task
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->statusOf(StatusCategory::NotStarted);
        $payload = ['title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id];
        if ($startOn !== null) {
            $payload['start_on'] = $startOn;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", $payload)
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function createColumn(string $name): TaskColumnDefinition
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => $name, 'type' => 'text',
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskColumnDefinition::findOrFail($id);
    }

    private function declareRecurrence(Task $task, string $mode, int $every, string $unit): void
    {
        $this->enterTenant();
        app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            Task::findOrFail($task->id),
            ['mode' => $mode, 'every' => $every, 'unit' => $unit],
            (int) Task::findOrFail($task->id)->version,
        );
    }

    private function declareRecurrenceWithBounds(
        Task $task,
        string $mode,
        int $every,
        string $unit,
        ?string $until = null,
        ?int $count = null,
    ): void {
        $this->enterTenant();
        app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            Task::findOrFail($task->id),
            ['mode' => $mode, 'every' => $every, 'unit' => $unit, 'until' => $until, 'count' => $count],
            (int) Task::findOrFail($task->id)->version,
        );
    }

    private function statusOf(StatusCategory $category): \App\Models\TaskStatusOption
    {
        return $this->board->statusOptions()->active()->where('category', $category)->firstOrFail();
    }

    private function rawTaskAttributes(string $title): array
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->statusOf(StatusCategory::NotStarted);

        return [
            'organization_id' => $this->tenant['org']->id,
            'group_id' => $group->id,
            'number' => 'T-TEST-'.uniqid(),
            'title' => $title,
            'status_option_id' => $status->id,
            'created_by' => $this->tenant['admin']->id,
            'updated_by' => $this->tenant['admin']->id,
        ];
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
