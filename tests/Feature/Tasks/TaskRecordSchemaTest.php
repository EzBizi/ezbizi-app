<?php

namespace Tests\Feature\Tasks;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskRecordSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_task_storage_enforces_top_level_and_subtask_identity_and_keeps_typed_values_projected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $now = now();

        $alphaBoard = $this->insertBoard($alpha, $now);
        $betaBoard = $this->insertBoard($beta, $now);
        $alphaGroup = $this->insertGroup($alpha, $alphaBoard, $now);
        $betaGroup = $this->insertGroup($beta, $betaBoard, $now);
        $alphaStatus = $this->insertStatus($alpha, $alphaBoard, $now);
        $betaStatus = $this->insertStatus($beta, $betaBoard, $now);
        $alphaPriority = $this->insertPriority($alpha, $alphaBoard, $now);
        $betaPriority = $this->insertPriority($beta, $betaBoard, $now);
        $alphaText = $this->insertColumn($alpha, $alphaBoard, 'text', $now);
        $alphaPeople = $this->insertColumn($alpha, $alphaBoard, 'people', $now);
        $alphaLabel = $this->insertColumn($alpha, $alphaBoard, 'label', $now);
        $alphaFile = $this->insertColumn($alpha, $alphaBoard, 'file', $now);
        $alphaOption = $this->insertOption($alpha, $alphaBoard, $alphaLabel, $now);

        $parentId = $this->insertTask($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, $alphaPriority, $now);
        $childId = $this->insertTask($alpha, $alphaBoard, null, $parentId, $alphaStatus, null, $now, position: -2);

        $parent = DB::table('tasks')->find($parentId);
        $child = DB::table('tasks')->find($childId);
        $this->assertSame($alphaGroup, $parent->group_id);
        $this->assertNull($parent->parent_task_id);
        $this->assertNull($child->group_id);
        $this->assertSame($parentId, $child->parent_task_id);
        $this->assertSame(-2, $child->position);
        $this->assertSame(1, $parent->version);
        $this->assertSame(1, $parent->detail_version);
        $this->assertSame(0, $parent->due_revision);

        $this->assertConstraintRejects(fn () => $this->insertTask(
            $alpha, $alphaBoard, $alphaGroup, $parentId, $alphaStatus, null, $now,
        ), 'tasks_group_xor_parent_check');

        $this->assertConstraintRejects(fn () => $this->insertTask(
            $alpha, $alphaBoard, null, $this->insertTask($beta, $betaBoard, $betaGroup, null, $betaStatus, null, $now),
            $alphaStatus, null, $now,
        ), 'tasks_parent_foreign');

        $this->assertConstraintRejects(fn () => $this->insertTask(
            $alpha, $alphaBoard, $betaGroup, null, $alphaStatus, null, $now,
        ), 'tasks_group_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'owner_user_id' => $beta['admin']->id,
        ]), 'tasks_owner_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'status_option_id' => $betaStatus,
        ]), 'tasks_status_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'priority_option_id' => $betaPriority,
        ]), 'tasks_priority_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'created_by' => $beta['admin']->id,
        ]), 'tasks_created_by_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'completed_at' => $now,
            'completed_by' => $beta['admin']->id,
        ]), 'tasks_completed_by_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'cancelled_at' => $now,
            'cancelled_by' => $beta['admin']->id,
        ]), 'tasks_cancelled_by_foreign');

        $this->assertConstraintRejects(fn () => DB::table('tasks')->insert([
            ...$this->taskRow($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now),
            'archived_with_parent_id' => $this->insertTask($beta, $betaBoard, $betaGroup, null, $betaStatus, null, $now),
        ]), 'tasks_archived_with_parent_foreign');

        DB::table('task_column_values')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $alpha['org']->id,
            'board_id' => $alphaBoard,
            'task_id' => $parentId,
            'column_definition_id' => $alphaText,
            'text_value' => 'A plain custom value',
            'number_value' => null,
            'boolean_value' => null,
            'date_value' => null,
            'url_value' => 'https://example.test/task',
            'link_label_value' => 'Reference',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('task_column_people')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaPeople, 'user_id' => $alpha['admin']->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('task_column_labels')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaLabel, 'column_option_id' => $alphaOption,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $attachmentId = (string) Str::uuid7();
        DB::table('attachments')->insert([
            'id' => $attachmentId, 'organization_id' => $alpha['org']->id,
            'attachable_type' => 'task', 'attachable_id' => $parentId, 'disk' => 'local', 'path' => 'tests/task.txt',
            'original_name' => 'task.txt', 'mime' => 'text/plain', 'size_bytes' => 4, 'checksum' => str_repeat('a', 64),
            'uploaded_by_user_id' => $alpha['admin']->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('task_column_files')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaFile, 'attachment_id' => $attachmentId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $value = DB::table('task_column_values')->where('task_id', $parentId)->first();
        $this->assertSame('A plain custom value', $value->text_value);
        $this->assertSame('https://example.test/task', $value->url_value);
        $this->assertSame('Reference', $value->link_label_value);
        $this->assertSame($alpha['admin']->id, DB::table('task_column_people')->where('task_id', $parentId)->value('user_id'));
        $this->assertSame($alphaOption, DB::table('task_column_labels')->where('task_id', $parentId)->value('column_option_id'));
        $this->assertSame($attachmentId, DB::table('task_column_files')->where('task_id', $parentId)->value('attachment_id'));

        $this->assertConstraintRejects(fn () => DB::table('task_column_values')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaText, 'created_at' => $now, 'updated_at' => $now,
        ]), 'task_column_values_task_definition_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_column_people')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaPeople, 'user_id' => $beta['admin']->id,
            'created_at' => $now, 'updated_at' => $now,
        ]), 'task_column_people_user_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_column_labels')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaLabel, 'column_option_id' => $this->insertOption($beta, $betaBoard, $this->insertColumn($beta, $betaBoard, 'label', $now), $now),
            'created_at' => $now, 'updated_at' => $now,
        ]), 'task_column_labels_option_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_column_files')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'task_id' => $parentId, 'column_definition_id' => $alphaFile, 'attachment_id' => (string) Str::uuid7(),
            'created_at' => $now, 'updated_at' => $now,
        ]), 'task_column_files_attachment_foreign');

        $reusablePosition = -100;
        $this->insertTask($alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now, $reusablePosition);
        $this->assertConstraintRejects(fn () => $this->insertTask(
            $alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now, $reusablePosition,
        ), 'tasks_parent_active_position_unique');
        DB::table('tasks')->where('organization_id', $alpha['org']->id)->where('board_id', $alphaBoard)
            ->where('position', $reusablePosition)->update(['archived_at' => $now]);
        $this->assertNotEmpty($this->insertTask(
            $alpha, $alphaBoard, $alphaGroup, null, $alphaStatus, null, $now, $reusablePosition,
        ));
    }

    /** @param array{org: object, admin: object} $tenant */
    private function insertBoard(array $tenant, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_boards')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'company_id' => null, 'franchise_id' => null,
            'folder_id' => null, 'name' => "Board {$id}", 'description' => null, 'visibility' => 'scope',
            'position' => 0, 'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertGroup(array $tenant, string $boardId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_groups')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Group {$id}", 'color' => 'blue', 'position' => 0, 'order_version' => 1,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertStatus(array $tenant, string $boardId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_status_options')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Status {$id}", 'color' => 'blue', 'category' => 'active', 'position' => 0,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertPriority(array $tenant, string $boardId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_priority_options')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Priority {$id}", 'color' => 'blue', 'rank' => 'normal', 'position' => 0,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertColumn(array $tenant, string $boardId, string $type, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_column_definitions')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'name' => "Column {$id}", 'type' => $type, 'number_suffix' => null, 'position' => random_int(1, 999999),
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object} $tenant */
    private function insertOption(array $tenant, string $boardId, string $columnId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('task_column_options')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId, 'column_definition_id' => $columnId,
            'name' => "Option {$id}", 'color' => 'blue', 'position' => 0, 'archived_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param array{org: object, admin: object} $tenant */
    private function insertTask(array $tenant, string $boardId, ?string $groupId, ?string $parentId, ?string $statusId, ?string $priorityId, mixed $now, int $position = 0): string
    {
        $row = $this->taskRow($tenant, $boardId, $groupId, $parentId, $statusId, $priorityId, $now, $position);
        DB::table('tasks')->insert($row);

        return $row['id'];
    }

    /** @param array{org: object, admin: object} $tenant @return array<string, mixed> */
    private function taskRow(array $tenant, string $boardId, ?string $groupId, ?string $parentId, ?string $statusId, ?string $priorityId, mixed $now, int $position = 0): array
    {
        return [
            'id' => (string) Str::uuid7(), 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'group_id' => $groupId, 'parent_task_id' => $parentId, 'number' => 'T-'.Str::random(16), 'title' => 'Task',
            'description' => '<p>Brief</p>', 'description_plain' => 'Brief', 'owner_user_id' => $tenant['admin']->id,
            'status_option_id' => $statusId, 'priority_option_id' => $priorityId, 'start_on' => '2026-08-26', 'due_on' => '2026-08-27',
            'due_revision' => 0, 'completed_at' => null, 'completed_by' => null, 'cancelled_at' => null, 'cancelled_by' => null,
            'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'position' => $position === 0 ? random_int(1, 999999) : $position,
            'version' => 1, 'detail_version' => 1, 'archived_with_parent_id' => null, 'archived_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function assertConstraintRejects(Closure $write, string $constraint): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
