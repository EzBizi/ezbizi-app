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

        // Merely editing the task (staying open) must create nothing, even
        // after a worker pass: no relay marker is ever emitted for it.
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'title' => 'Service the truck (updated)',
        ])->assertOk();
        $this->enterTenant();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();
        $this->enterTenant();
        $this->assertSame(0, TaskRecurrenceOccurrence::count());
        $this->assertSame(0, AutomationEvent::where('event_key', 'task.recurrence_instance_due')->count());

        $before = $this->board->tasks()->count();
        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();

        // Completion relays a marker to the outbox; it does not create the
        // instance synchronously (fix round 1: the completion transaction
        // must never carry the lock-heavy work).
        $this->enterTenant();
        $this->assertSame($before, $this->board->tasks()->count());
        $this->assertSame(0, TaskRecurrenceOccurrence::count());
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.recurrence_instance_due')
            ->where('subject_id', $task->id)->count());

        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

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

    public function test_after_completion_series_continues_into_a_third_generation_because_the_instance_carries_recurrence(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Service the truck');
        $this->declareRecurrence($task, 'after_completion', 3, 'month');

        // Generation 1 -> 2.
        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $generationTwo = Task::findOrFail(
            TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail()->created_task_id
        );
        $this->assertNotNull($generationTwo->recurrence, 'The instance must carry recurrence forward, or the series dies here.');
        // Read by key, not position: MySQL does not preserve JSON key order
        // (same rule TaskRecurrenceCodec's own comment documents).
        $this->assertEquals([
            'mode' => 'after_completion', 'every' => 3, 'unit' => 'month', 'until' => null, 'count' => null,
            'declared_on' => '2026-09-01',
        ], $generationTwo->recurrence);

        // Generation 2 -> 3. The series root for occurrence bookkeeping is
        // STILL the original task (task_id on the occurrence row never
        // changes) — generation 2 completing must produce generation 3.
        // The org's trial window is relative to real wall-clock time at
        // provisioning, so jumping the test clock three months ahead would
        // otherwise make the organization read-only; extend it first.
        $this->enterTenant();
        $this->tenant['org']->forceFill(['trial_ends_at' => null])->save();
        \App\Support\Subscriptions\SubscriptionGuard::flush();
        // Sanctum::actingAs (via signedInAs, in setUp) pins one specific User
        // PHP object as the request's authenticated user for the rest of the
        // test; its `organization` relation, once accessed, stays cached on
        // that object regardless of the DB update above. Re-sign-in so
        // EnforceSubscriptionState's `$request->user()->organization` reads
        // the trial we just cleared, not the one from setUp.
        $this->signedInAs($this->tenant['admin']->fresh());
        Carbon::setTestNow('2026-12-01 12:00:00');
        $this->patchJson("/api/v1/tasks/{$generationTwo->id}", [
            'expected_version' => Task::findOrFail($generationTwo->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(2, TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNotNull('created_task_id')->count());
        $generationThree = Task::findOrFail(
            TaskRecurrenceOccurrence::where('task_id', $task->id)->orderBy('occurrence_date', 'desc')->firstOrFail()->created_task_id
        );
        $this->assertSame('2027-03-01', $generationThree->due_on?->format('Y-m-d'));
        $this->assertSame($generationTwo->recurrence, $generationThree->recurrence);
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
        $instances = Task::where('board_id', $this->board->id)
            ->where('title', 'Weekly inspection')->where('id', '!=', $task->id)->get();
        $this->assertSame(3, $instances->count());

        // Deliberate, not accidental: on_schedule instances do NOT carry
        // recurrence forward. The root keeps generating every cycle by
        // re-scanning itself by id; copying recurrence onto each instance
        // too would make every one of them a second, uncoordinated series
        // root on the next sweep.
        $this->assertTrue($instances->every(fn (Task $instance) => $instance->recurrence === null));
    }

    /**
     * Fix round 2 (task-6): one candidate throwing must not abort the rest
     * of the batch. Seeded the same way fix round 1's finding actually
     * happened — a board where buildInstance() cannot find a non-terminal
     * status to put the new instance in, so it throws instead of returning
     * a refusal — proving TaskRecurrenceWorker::runCandidate() actually
     * isolates it, not merely that a try/catch exists somewhere.
     */
    public function test_one_failing_candidate_does_not_stop_the_rest_of_the_batch(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $badBoard = $this->createBoard('Broken board');
        $badTask = $this->createTaskOnBoard($badBoard, 'Bad series', startOn: '2026-09-01');
        $this->declareRecurrence($badTask, 'on_schedule', 1, 'day');
        // Archived AFTER the task exists: what must break is buildInstance()
        // finding a non-terminal status for the NEW instance, not the
        // creation of the seed task itself.
        $this->enterTenant();
        \App\Models\TaskStatusOption::where('board_id', $badBoard->id)
            ->whereIn('category', [StatusCategory::NotStarted, StatusCategory::Active, StatusCategory::Blocked])
            ->update(['archived_at' => now()]);

        $goodTask = $this->createTask('Good series', startOn: '2026-09-01');
        $this->declareRecurrence($goodTask, 'on_schedule', 1, 'day');

        Carbon::setTestNow('2026-09-02 12:00:00');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(0, TaskRecurrenceOccurrence::where('task_id', $badTask->id)->count(),
            'The failing candidate must not have partially recorded anything.');
        $this->assertSame(1, TaskRecurrenceOccurrence::where('task_id', $goodTask->id)->count(),
            'A candidate queued behind a failing one must still be processed.');
        $this->assertSame(1, Task::where('board_id', $this->board->id)
            ->where('title', 'Good series')->where('id', '!=', $goodTask->id)->count());
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

    public function test_relayed_after_completion_retried_run_creates_no_duplicate(): void
    {
        // The outbox relay is what makes after_completion susceptible to a
        // genuine retried/overlapping RUN in the first place (fix round
        // 1) — a synchronous call could never be "retried" independently
        // of the completion itself. Same guarantee, same mechanism
        // (occurrence uniqueness), different trigger.
        Carbon::setTestNow('2026-09-01 12:00:00');
        $task = $this->createTask('Relay retry me');
        $this->declareRecurrence($task, 'after_completion', 1, 'day');
        $completed = $this->statusOf(StatusCategory::Completed);
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => Task::findOrFail($task->id)->version, 'status_option_id' => $completed->id,
        ])->assertOk();

        $this->enterTenant();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(1, TaskRecurrenceOccurrence::where('task_id', $task->id)->count());
        $this->assertSame(1, Task::where('board_id', $this->board->id)
            ->where('title', 'Relay retry me')->where('id', '!=', $task->id)->count());
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
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $occurrence = TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('2026-09-01', $occurrence->occurrence_date->format('Y-m-d'));
    }

    // ── declaration floor (whole-branch review, finding 1) ──────────

    /**
     * The core regression: declaring on_schedule on a task whose start_on
     * is months in the past must NOT walk every occurrence between then
     * and now. Floored at the declaration date, the walk produces exactly
     * one instance — the next occurrence on/after today — not a backlog
     * of ~243 tasks.
     */
    public function test_on_schedule_declared_long_after_the_tasks_baseline_creates_only_the_next_occurrence(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        $task = $this->createTask('Old task', startOn: '2026-01-01');

        // Recurrence is declared 243 days later, on an ordinary task that
        // was never meant to be recurring until now.
        Carbon::setTestNow('2026-08-31 12:00:00');
        $this->declareRecurrence($task, 'on_schedule', 1, 'day');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $this->assertSame(
            1,
            TaskRecurrenceOccurrence::where('task_id', $task->id)->count(),
            'Declaring recurrence on a months-old task must not backfill every occurrence since its start_on.'
        );
        $occurrence = TaskRecurrenceOccurrence::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('2026-08-31', $occurrence->occurrence_date->format('Y-m-d'));
        $this->assertNotNull($occurrence->created_task_id);
        $instances = Task::where('board_id', $this->board->id)
            ->where('title', 'Old task')->where('id', '!=', $task->id)->get();
        $this->assertSame(1, $instances->count());
    }

    /**
     * `count` must still be measured from the declaration forward, not
     * from the task's historical baseline — a series declared long after
     * its baseline must get its full `count` of future occurrences, not
     * exhaust immediately because the raw cycle number (baseline to now)
     * already exceeds `count`.
     */
    public function test_count_exhaustion_is_measured_from_the_declaration_not_the_historical_baseline(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        $task = $this->createTask('Old bounded task', startOn: '2026-01-01');

        Carbon::setTestNow('2026-08-31 12:00:00');
        $this->declareRecurrenceWithBounds($task, 'on_schedule', 1, 'day', count: 2);

        Carbon::setTestNow('2026-09-10 12:00:00');
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

        $this->enterTenant();
        $created = TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNotNull('created_task_id')->count();
        $this->assertSame(2, $created, 'A count of 2 declared today must still produce 2 occurrences, not zero.');
        $this->assertNotNull(TaskRecurrenceOccurrence::where('task_id', $task->id)->whereNull('created_task_id')->first());
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
        $this->artisan('ezbizi:create-recurring-tasks')->assertSuccessful();

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
        return $this->createTaskOnBoard($this->board, $title, $startOn, $description);
    }

    private function createTaskOnBoard(TaskBoard $board, string $title, ?string $startOn = null, ?string $description = null): Task
    {
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $payload = ['title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id];
        if ($startOn !== null) {
            $payload['start_on'] = $startOn;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", $payload)
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function createBoard(string $name): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', ['name' => $name, 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskBoard::findOrFail($id);
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
