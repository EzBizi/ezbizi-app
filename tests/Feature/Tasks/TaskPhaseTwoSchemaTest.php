<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskDependency;
use App\Models\TaskRecurrenceOccurrence;
use App\Support\Privacy\RetentionPolicy;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskPhaseTwoSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_tasks_gains_a_nullable_recurrence_column(): void
    {
        $migration = require database_path('migrations/2026_08_29_100000_add_task_phase_two_storage.php');
        $migration->down();

        try {
            // start_on already exists — Phase 1 created it. This migration
            // does not touch it, so it survives the rollback.
            $this->assertTrue(Schema::hasColumn('tasks', 'start_on'));
            $this->assertFalse(Schema::hasColumn('tasks', 'recurrence'));
            $this->assertFalse(Schema::hasTable('task_dependencies'));
            $this->assertFalse(Schema::hasTable('task_recurrence_occurrences'));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('tasks', 'recurrence'));
        $this->assertTrue($this->isNullable('tasks', 'recurrence'));
        $this->assertSame('json', $this->columnType('tasks', 'recurrence'));

        $tenant = $this->provisionTenant('Alpha');
        $now = now();
        [$boardId, $groupId, $statusId] = $this->insertBoard($tenant, $now);
        $taskId = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now);

        $task = DB::table('tasks')->find($taskId);
        $this->assertNull($task->recurrence);

        $this->assertTrue($this->hasIndex('tasks', 'board_id,start_on,due_on'));
    }

    public function test_task_dependencies_rejects_cross_tenant_cross_board_self_and_duplicate_pairs(): void
    {
        $this->assertTrue(Schema::hasTable('task_dependencies'));

        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $now = now();

        [$alphaBoard, $alphaGroup, $alphaStatus] = $this->insertBoard($alpha, $now);
        [$alphaOtherBoard, $alphaOtherGroup, $alphaOtherStatus] = $this->insertBoard($alpha, $now);
        [$betaBoard, $betaGroup, $betaStatus] = $this->insertBoard($beta, $now);

        $blocker = $this->insertTask($alpha, $alphaBoard, $alphaGroup, $alphaStatus, $now, 'Blocker');
        $blocked = $this->insertTask($alpha, $alphaBoard, $alphaGroup, $alphaStatus, $now, 'Blocked');
        $alphaOtherBoardTask = $this->insertTask($alpha, $alphaOtherBoard, $alphaOtherGroup, $alphaOtherStatus, $now, 'Other board');
        $betaTask = $this->insertTask($beta, $betaBoard, $betaGroup, $betaStatus, $now, 'Beta task');

        // A valid edge is accepted.
        $edgeId = $this->insertDependency($alpha, $alphaBoard, $blocker, $blocked, $now);
        $this->assertNotEmpty($edgeId);

        // Duplicate (blocker, blocked) pair is rejected by the named unique key.
        $this->assertConstraintRejects(
            fn () => $this->insertDependency($alpha, $alphaBoard, $blocker, $blocked, $now),
            'task_dependencies_pair_unique',
        );

        // A task cannot block itself.
        $this->assertConstraintRejects(
            fn () => $this->insertDependency($alpha, $alphaBoard, $blocker, $blocker, $now),
            'task_dependencies_not_self_check',
        );

        // Cross-tenant: blocked_task_id belongs to another organization entirely.
        $this->assertConstraintRejects(
            fn () => $this->insertDependency($alpha, $alphaBoard, $blocker, $betaTask, $now),
            'task_dependencies_blocked_foreign',
        );

        // Cross-board: blocked task exists in the same organization but on a
        // different board than the dependency's own board_id names.
        $this->assertConstraintRejects(
            fn () => $this->insertDependency($alpha, $alphaBoard, $blocker, $alphaOtherBoardTask, $now),
            'task_dependencies_blocked_foreign',
        );
        $this->assertConstraintRejects(
            fn () => $this->insertDependency($alpha, $alphaBoard, $alphaOtherBoardTask, $blocked, $now),
            'task_dependencies_blocker_foreign',
        );

        foreach (['blocked_task_id', 'blocker_task_id'] as $column) {
            $this->assertTrue($this->hasIndex('task_dependencies', $column), $column);
        }
    }

    public function test_task_dependency_disappears_when_either_task_is_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $now = now();
        [$boardId, $groupId, $statusId] = $this->insertBoard($tenant, $now);
        $blocker = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Blocker');
        $blockedByDeletingBlocker = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Blocked A');
        $blockerForDeletingBlocked = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Blocker B');
        $blocked = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Blocked B');

        $firstEdge = $this->insertDependency($tenant, $boardId, $blocker, $blockedByDeletingBlocker, $now);
        $secondEdge = $this->insertDependency($tenant, $boardId, $blockerForDeletingBlocked, $blocked, $now);

        DB::table('tasks')->where('id', $blocker)->delete();
        $this->assertNull(DB::table('task_dependencies')->find($firstEdge));

        DB::table('tasks')->where('id', $blocked)->delete();
        $this->assertNull(DB::table('task_dependencies')->find($secondEdge));
    }

    public function test_task_recurrence_occurrences_enforces_task_date_uniqueness_and_allows_a_taskless_final_occurrence(): void
    {
        $this->assertTrue(Schema::hasTable('task_recurrence_occurrences'));

        $tenant = $this->provisionTenant('Alpha');
        $now = now();
        [$boardId, $groupId, $statusId] = $this->insertBoard($tenant, $now);
        $seriesTask = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Series');
        $createdTask = $this->insertTask($tenant, $boardId, $groupId, $statusId, $now, 'Created instance');

        $firstOccurrence = $this->insertOccurrence($tenant, $boardId, $seriesTask, '2026-09-01', $createdTask, $now);
        $this->assertSame($createdTask, DB::table('task_recurrence_occurrences')->find($firstOccurrence)->created_task_id);

        // Retried/overlapping worker run on the same occurrence date is rejected.
        $this->assertConstraintRejects(
            fn () => $this->insertOccurrence($tenant, $boardId, $seriesTask, '2026-09-01', $createdTask, $now),
            'task_recurrence_occurrences_task_date_unique',
        );

        // A different occurrence date for the same series is fine.
        $secondOccurrence = $this->insertOccurrence($tenant, $boardId, $seriesTask, '2026-10-01', $createdTask, $now);
        $this->assertNotEmpty($secondOccurrence);

        // A final occurrence records no created task at all (series ended).
        $finalOccurrence = $this->insertOccurrence($tenant, $boardId, $seriesTask, '2026-11-01', null, $now);
        $this->assertNull(DB::table('task_recurrence_occurrences')->find($finalOccurrence)->created_task_id);
    }

    public function test_task_recurrence_occurrences_rejects_a_task_from_another_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $now = now();
        [$betaBoard, $betaGroup, $betaStatus] = $this->insertBoard($beta, $now);
        $betaTask = $this->insertTask($beta, $betaBoard, $betaGroup, $betaStatus, $now, 'Beta series');

        // organization_id says Alpha; task_id names a task that belongs to
        // Beta. Only the compound (organization_id, board_id, task_id) foreign
        // key against tasks(organization_id, board_id, id) can catch this —
        // organization_id alone is only constrained to be a real organization.
        $this->assertConstraintRejects(
            fn () => $this->insertOccurrence($alpha, $betaBoard, $betaTask, '2026-09-01', null, $now, organizationId: $alpha['org']->id),
            'task_recurrence_occurrences_task_foreign',
        );

        // The same cross-tenant mismatch on created_task_id is rejected too.
        [$alphaBoard, $alphaGroup, $alphaStatus] = $this->insertBoard($alpha, $now);
        $alphaTask = $this->insertTask($alpha, $alphaBoard, $alphaGroup, $alphaStatus, $now, 'Alpha series');
        $this->assertConstraintRejects(
            fn () => $this->insertOccurrence($alpha, $alphaBoard, $alphaTask, '2026-09-01', $betaTask, $now),
            'task_recurrence_occurrences_created_task_foreign',
        );
    }

    public function test_new_models_declare_retention_and_personal_data_purpose(): void
    {
        $dependencyPolicy = TaskDependency::retention();
        $this->assertSame(RetentionPolicy::FOREVER, $dependencyPolicy->kind);
        $this->assertNotSame('', trim($dependencyPolicy->because));
        $this->assertNotSame('', trim(TaskDependency::personalDataPurpose()));
        $this->assertNotEmpty(TaskDependency::personalDataHeld());

        $occurrencePolicy = TaskRecurrenceOccurrence::retention();
        // forever(), mirroring TaskOverdueOccurrence: the key stays as durable
        // idempotency evidence. Phase 2 builds no cleaner for this table — Task
        // 6 only ever writes occurrences, so elsewhere() would name a mechanism
        // that does not exist, which is the false-retention defect this repo's
        // design exists to prevent.
        $this->assertSame(RetentionPolicy::FOREVER, $occurrencePolicy->kind);
        $this->assertNull($occurrencePolicy->enforcedBy);
        $this->assertNotSame('', trim($occurrencePolicy->because));
        $this->assertStringStartsWith('Kept indefinitely.', $occurrencePolicy->sentence());
        $this->assertNotSame('', trim(TaskRecurrenceOccurrence::personalDataPurpose()));
        $this->assertNotEmpty(TaskRecurrenceOccurrence::personalDataHeld());
    }

    /** @param array{org: object, admin: object} $tenant @return array{string,string,string} */
    private function insertBoard(array $tenant, mixed $now): array
    {
        $boardId = (string) Str::uuid7();
        DB::table('task_boards')->insert([
            'id' => $boardId, 'organization_id' => $tenant['org']->id, 'company_id' => null, 'franchise_id' => null,
            'folder_id' => null, 'name' => "Board {$boardId}", 'description' => null, 'visibility' => 'scope',
            'position' => random_int(1, 999999), 'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $groupId = (string) Str::uuid7();
        DB::table('task_groups')->insert([
            'id' => $groupId, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Group {$groupId}", 'color' => 'blue', 'position' => 0, 'order_version' => 1,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $statusId = (string) Str::uuid7();
        DB::table('task_status_options')->insert([
            'id' => $statusId, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Status {$statusId}", 'color' => 'blue', 'category' => 'active', 'position' => 0,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$boardId, $groupId, $statusId];
    }

    /** @param array{org: object, admin: object} $tenant */
    private function insertTask(array $tenant, string $boardId, string $groupId, string $statusId, mixed $now, ?string $title = null): string
    {
        $taskId = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $taskId, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'group_id' => $groupId, 'parent_task_id' => null, 'number' => 'T-'.Str::random(16), 'title' => $title ?? 'Task',
            'description' => null, 'description_plain' => null, 'owner_user_id' => $tenant['admin']->id,
            'status_option_id' => $statusId, 'priority_option_id' => null, 'start_on' => null, 'due_on' => null,
            'recurrence' => null,
            'due_revision' => 0, 'completed_at' => null, 'completed_by' => null, 'cancelled_at' => null,
            'cancelled_by' => null, 'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'position' => random_int(1, 999999), 'version' => 1, 'detail_version' => 1, 'archived_with_parent_id' => null,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $taskId;
    }

    /** @param array{org: object, admin: object} $tenant */
    private function insertDependency(array $tenant, string $boardId, string $blockerTaskId, string $blockedTaskId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_dependencies')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'blocker_task_id' => $blockerTaskId, 'blocked_task_id' => $blockedTaskId,
            'created_by' => $tenant['admin']->id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertOccurrence(array $tenant, string $boardId, string $taskId, string $occurrenceDate, ?string $createdTaskId, mixed $now, ?string $organizationId = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_recurrence_occurrences')->insert([
            'id' => $id, 'organization_id' => $organizationId ?? $tenant['org']->id, 'board_id' => $boardId,
            'task_id' => $taskId, 'occurrence_date' => $occurrenceDate, 'created_task_id' => $createdTaskId,
            'created_at' => $now,
        ]);

        return $id;
    }

    private function isNullable(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable') === 'YES';
    }

    private function columnType(string $table, string $column): string
    {
        return DB::table('information_schema.columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('data_type');
    }

    private function hasIndex(string $table, string $columns): bool
    {
        return DB::table('information_schema.statistics')
            ->selectRaw('index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->groupBy('index_name')
            ->get()
            ->contains(fn (object $index): bool => $index->columns === $columns);
    }

    private function assertConstraintRejects(Closure $write, string $constraint): void
    {
        try {
            $write();
            $this->fail("Expected MySQL to reject the invalid row via {$constraint}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
