<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskConflict;
use App\Support\Tasks\TaskRecurrence;
use App\Support\Tasks\TaskRecurrenceCodec;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskRecurrenceDeclarationTest extends TestCase
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

    // ── TaskRecurrenceCodec ─────────────────────────────────────

    public function test_after_completion_mode_round_trips_through_parse_and_encode(): void
    {
        $raw = ['mode' => 'after_completion', 'every' => 3, 'unit' => 'day', 'until' => null, 'count' => 5];

        $recurrence = TaskRecurrenceCodec::parse($raw);

        $this->assertInstanceOf(TaskRecurrence::class, $recurrence);
        $this->assertSame($raw, TaskRecurrenceCodec::encode($recurrence));
    }

    public function test_on_schedule_mode_round_trips_through_parse_and_encode(): void
    {
        $raw = ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'month', 'until' => '2027-01-01', 'count' => null];

        $recurrence = TaskRecurrenceCodec::parse($raw);

        $this->assertInstanceOf(TaskRecurrence::class, $recurrence);
        $this->assertSame($raw, TaskRecurrenceCodec::encode($recurrence));
    }

    public function test_every_zero_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        TaskRecurrenceCodec::parse(['mode' => 'on_schedule', 'every' => 0, 'unit' => 'day']);
    }

    public function test_negative_count_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        TaskRecurrenceCodec::parse(['mode' => 'on_schedule', 'every' => 1, 'unit' => 'day', 'count' => -1]);
    }

    public function test_unknown_unit_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        TaskRecurrenceCodec::parse(['mode' => 'on_schedule', 'every' => 1, 'unit' => 'fortnight']);
    }

    public function test_unknown_mode_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        TaskRecurrenceCodec::parse(['mode' => 'whenever', 'every' => 1, 'unit' => 'day']);
    }

    public function test_until_and_count_together_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        TaskRecurrenceCodec::parse([
            'mode' => 'on_schedule', 'every' => 1, 'unit' => 'week',
            'until' => '2027-01-01', 'count' => 4,
        ]);
    }

    public function test_json_is_read_by_key_regardless_of_order(): void
    {
        $inOrder = ['mode' => 'on_schedule', 'every' => 2, 'unit' => 'week', 'until' => '2027-06-01', 'count' => null];
        $reordered = ['count' => null, 'unit' => 'week', 'until' => '2027-06-01', 'every' => 2, 'mode' => 'on_schedule'];

        $fromInOrder = TaskRecurrenceCodec::parse($inOrder);
        $fromReordered = TaskRecurrenceCodec::parse($reordered);

        $this->assertEquals($fromInOrder, $fromReordered);
        $this->assertSame(TaskRecurrenceCodec::encode($fromInOrder), TaskRecurrenceCodec::encode($fromReordered));
    }

    // ── TaskWriter::setRecurrence ───────────────────────────────

    /**
     * Fix round 2 (task-6): a subtask cannot declare recurrence, in either
     * mode. Both modes reach the same `tasks_group_xor_parent_check`
     * violation by the same path once a worker tries to build an instance
     * (buildInstance() copies `group`, which only a top-level task has), so
     * both are refused at declaration rather than one of them surfacing a
     * database error hours later.
     */
    public function test_declaring_either_mode_on_a_subtask_is_refused(): void
    {
        $parent = $this->createTask('Parent');
        $subtask = $this->createSubtask($parent, 'Checklist item');

        foreach (['after_completion', 'on_schedule'] as $mode) {
            try {
                app(TaskWriter::class)->setRecurrence(
                    $this->tenant['admin'],
                    $subtask,
                    ['mode' => $mode, 'every' => 1, 'unit' => 'day'],
                    (int) $subtask->version,
                );
                $this->fail("Expected declaring {$mode} on a subtask to be refused.");
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                $this->assertStringContainsString('cannot recur', $e->getMessage());
            }
        }

        $this->enterTenant();
        $this->assertNull(Task::findOrFail($subtask->id)->recurrence);
    }

    public function test_declaring_either_mode_on_a_top_level_task_still_works(): void
    {
        foreach (['after_completion', 'on_schedule'] as $mode) {
            $task = $this->createTask("Top level {$mode}");

            $declared = app(TaskWriter::class)->setRecurrence(
                $this->tenant['admin'],
                $task,
                ['mode' => $mode, 'every' => 1, 'unit' => 'day'],
                (int) $task->version,
            );

            $this->assertNotNull($declared->recurrence);
            $this->assertSame($mode, $declared->recurrence['mode']);
        }
    }

    public function test_declaration_is_a_versioned_write_that_conflicts_on_a_stale_version(): void
    {
        $task = $this->createTask('Recurring');
        $staleVersion = (int) $task->version;

        app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            $task,
            ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'week'],
            $staleVersion,
        );

        $this->expectException(TaskConflict::class);

        app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            $task,
            ['mode' => 'on_schedule', 'every' => 2, 'unit' => 'week'],
            $staleVersion,
        );
    }

    public function test_declaration_writes_an_audit_row(): void
    {
        $task = $this->createTask('Recurring');

        app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            $task,
            ['mode' => 'after_completion', 'every' => 1, 'unit' => 'day'],
            (int) $task->version,
        );

        $this->assertTrue(
            AuditEvent::query()
                ->where('target_type', Task::class)
                ->where('target_id', $task->id)
                ->where('action', 'task.recurrence_declared')
                ->exists()
        );
    }

    public function test_clearing_to_null_is_allowed_and_audited(): void
    {
        $task = $this->createTask('Recurring');
        $declared = app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            $task,
            ['mode' => 'after_completion', 'every' => 1, 'unit' => 'day'],
            (int) $task->version,
        );
        $this->assertNotNull($declared->recurrence);

        $cleared = app(TaskWriter::class)->setRecurrence(
            $this->tenant['admin'],
            $declared,
            null,
            (int) $declared->version,
        );

        $this->assertNull($cleared->recurrence);
        $this->assertSame(
            2,
            AuditEvent::query()
                ->where('target_type', Task::class)
                ->where('target_id', $task->id)
                ->where('action', 'task.recurrence_declared')
                ->count()
        );
    }

    private function createTask(string $title): Task
    {
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function createSubtask(Task $parent, string $title): Task
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'parent_task_id' => $parent->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }
}
