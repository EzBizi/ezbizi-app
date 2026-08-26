<?php

namespace Tests\Feature\Tasks;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskCollaborationSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_collaboration_storage_enforces_task_user_update_attachment_and_link_identity(): void
    {
        $migration = require database_path('migrations/2026_08_25_172000_create_task_collaboration_tables.php');
        $migration->down();

        try {
            $this->assertFalse(Schema::hasTable('task_collaborators'));
        } finally {
            $migration->up();
        }

        foreach ([
            'task_collaborators',
            'task_watch_preferences',
            'task_updates',
            'task_update_versions',
            'task_update_mentions',
            'task_update_attachment_candidates',
            'task_activities',
            'task_record_links',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        $this->assertFalse(Schema::hasColumn('task_update_versions', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('task_activities', 'updated_at'));

        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $alphaOther = $this->userWithRole($alpha, 'Alpha Collaborator');
        $now = now();
        [$alphaBoard, $alphaTask] = $this->insertBoardAndTask($alpha, $now);
        [$betaBoard, $betaTask] = $this->insertBoardAndTask($beta, $now);

        $collaborator = $this->identityRow($alpha, $alphaBoard, $alphaTask);
        DB::table('task_collaborators')->insert([
            ...$collaborator, 'user_id' => $alpha['admin']->id, 'added_by' => $alpha['admin']->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertConstraintRejects(fn () => DB::table('task_collaborators')->insert([
            ...$collaborator, 'id' => (string) Str::uuid7(), 'user_id' => $alpha['admin']->id,
            'added_by' => $alpha['admin']->id, 'created_at' => $now, 'updated_at' => $now,
        ]), 'task_collaborators_task_user_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_collaborators')->insert([
            ...$collaborator, 'id' => (string) Str::uuid7(), 'user_id' => $beta['admin']->id,
            'added_by' => $alpha['admin']->id, 'created_at' => $now, 'updated_at' => $now,
        ]), 'task_collaborators_user_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_collaborators')->insert([
            ...$collaborator, 'id' => (string) Str::uuid7(), 'user_id' => $alphaOther->id,
            'added_by' => $beta['admin']->id, 'created_at' => $now, 'updated_at' => $now,
        ]), 'task_collaborators_added_by_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_collaborators')->insert([
            ...$collaborator, 'id' => (string) Str::uuid7(), 'task_id' => $betaTask,
            'user_id' => $alphaOther->id, 'added_by' => $alpha['admin']->id,
            'created_at' => $now, 'updated_at' => $now,
        ]), 'task_collaborators_task_foreign');

        $watch = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'user_id' => $alpha['admin']->id,
            'preference' => 'watched', 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('task_watch_preferences')->insert($watch);
        $this->assertSame('watched', DB::table('task_watch_preferences')->value('preference'));
        $this->assertQueryRejects(fn () => DB::table('task_watch_preferences')->insert([
            ...$watch, 'id' => (string) Str::uuid7(), 'user_id' => $alphaOther->id, 'preference' => 'following',
        ]));
        $this->assertConstraintRejects(fn () => DB::table('task_watch_preferences')->insert([
            ...$watch, 'id' => (string) Str::uuid7(), 'user_id' => $beta['admin']->id, 'preference' => 'muted',
        ]), 'task_watch_preferences_user_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_watch_preferences')->insert([
            ...$watch, 'id' => (string) Str::uuid7(), 'preference' => 'muted',
        ]), 'task_watch_preferences_task_user_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_watch_preferences')->insert([
            ...$watch, 'id' => (string) Str::uuid7(), 'task_id' => $betaTask,
            'user_id' => $alphaOther->id, 'preference' => 'muted',
        ]), 'task_watch_preferences_task_foreign');

        $updateId = (string) Str::uuid7();
        $update = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask, $updateId),
            'author_user_id' => $alpha['admin']->id, 'body' => '<p>Update</p>', 'body_plain' => 'Update',
            'version' => 1, 'idempotency_key' => 'request-alpha', 'archived_at' => null, 'archived_by' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('task_updates')->insert($update);
        $this->assertSame(1, DB::table('task_updates')->find($updateId)->version);
        $this->assertConstraintRejects(fn () => DB::table('task_updates')->insert([
            ...$update, 'id' => (string) Str::uuid7(),
        ]), 'task_updates_idempotency_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_updates')->insert([
            ...$update, 'id' => (string) Str::uuid7(), 'idempotency_key' => 'wrong-author',
            'author_user_id' => $beta['admin']->id,
        ]), 'task_updates_author_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_updates')->insert([
            ...$update, 'id' => (string) Str::uuid7(), 'idempotency_key' => 'wrong-task',
            'task_id' => $betaTask,
        ]), 'task_updates_task_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_updates')->insert([
            ...$update, 'id' => (string) Str::uuid7(), 'idempotency_key' => 'wrong-archiver',
            'archived_at' => $now, 'archived_by' => $beta['admin']->id,
        ]), 'task_updates_archived_by_foreign');

        $betaUpdateId = (string) Str::uuid7();
        DB::table('task_updates')->insert([
            ...$this->identityRow($beta, $betaBoard, $betaTask, $betaUpdateId),
            'author_user_id' => $beta['admin']->id, 'body' => '<p>Beta</p>', 'body_plain' => 'Beta',
            'version' => 1, 'idempotency_key' => 'request-beta', 'archived_at' => null, 'archived_by' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $revision = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'update_id' => $updateId,
            'version' => 1, 'body' => '<p>Original</p>', 'body_plain' => 'Original',
            'edited_by' => $alpha['admin']->id, 'created_at' => $now,
        ];
        DB::table('task_update_versions')->insert($revision);
        $this->assertConstraintRejects(fn () => DB::table('task_update_versions')->insert([
            ...$revision, 'id' => (string) Str::uuid7(),
        ]), 'task_update_versions_update_version_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_update_versions')->insert([
            ...$revision, 'id' => (string) Str::uuid7(), 'update_id' => $betaUpdateId, 'version' => 2,
        ]), 'task_update_versions_update_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_update_versions')->insert([
            ...$revision, 'id' => (string) Str::uuid7(), 'version' => 2, 'edited_by' => $beta['admin']->id,
        ]), 'task_update_versions_editor_foreign');

        $mention = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'update_id' => $updateId,
            'user_id' => $alpha['admin']->id, 'created_at' => $now,
        ];
        DB::table('task_update_mentions')->insert($mention);
        $this->assertConstraintRejects(fn () => DB::table('task_update_mentions')->insert([
            ...$mention, 'id' => (string) Str::uuid7(),
        ]), 'task_update_mentions_update_user_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_update_mentions')->insert([
            ...$mention, 'id' => (string) Str::uuid7(), 'user_id' => $beta['admin']->id,
        ]), 'task_update_mentions_user_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_update_mentions')->insert([
            ...$mention, 'id' => (string) Str::uuid7(), 'update_id' => $betaUpdateId,
            'user_id' => $alphaOther->id,
        ]), 'task_update_mentions_update_foreign');

        $alphaAttachment = $this->insertAttachment($alpha, $alphaTask, $now);
        $alphaOtherAttachment = $this->insertAttachment($alpha, $alphaTask, $now);
        $alphaTaskMismatchAttachment = $this->insertAttachment($alpha, $alphaTask, $now);
        $betaAttachment = $this->insertAttachment($beta, $betaTask, $now);
        $candidate = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'attachment_id' => $alphaAttachment,
            'actor_user_id' => $alpha['admin']->id, 'expires_at' => $now->copy()->addDay(),
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('task_update_attachment_candidates')->insert($candidate);
        $this->assertConstraintRejects(fn () => DB::table('task_update_attachment_candidates')->insert([
            ...$candidate, 'id' => (string) Str::uuid7(),
        ]), 'task_update_candidates_attachment_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_update_attachment_candidates')->insert([
            ...$candidate, 'id' => (string) Str::uuid7(), 'attachment_id' => $betaAttachment,
        ]), 'task_update_candidates_attachment_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_update_attachment_candidates')->insert([
            ...$candidate, 'id' => (string) Str::uuid7(), 'attachment_id' => $alphaOtherAttachment,
            'actor_user_id' => $beta['admin']->id,
        ]), 'task_update_candidates_actor_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_update_attachment_candidates')->insert([
            ...$candidate, 'id' => (string) Str::uuid7(), 'task_id' => $betaTask,
            'attachment_id' => $alphaTaskMismatchAttachment,
        ]), 'task_update_candidates_task_foreign');

        DB::table('task_activities')->insert([
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'actor_user_id' => $alpha['admin']->id,
            'kind' => 'status.changed', 'summary' => 'Status changed', 'data' => json_encode(['to' => 'done']),
            'created_at' => $now,
        ]);
        $this->assertConstraintRejects(fn () => DB::table('task_activities')->insert([
            ...$this->identityRow($alpha, $alphaBoard, $betaTask), 'actor_user_id' => $alpha['admin']->id,
            'kind' => 'status.changed', 'summary' => 'Wrong task', 'data' => null, 'created_at' => $now,
        ]), 'task_activities_task_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_activities')->insert([
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'actor_user_id' => $beta['admin']->id,
            'kind' => 'status.changed', 'summary' => 'Wrong actor', 'data' => null, 'created_at' => $now,
        ]), 'task_activities_actor_foreign');

        $link = [
            ...$this->identityRow($alpha, $alphaBoard, $alphaTask), 'record_type' => 'customer',
            'record_id' => (string) Str::uuid7(), 'display_snapshot' => json_encode(['label' => 'Acme']),
            'created_by' => $alpha['admin']->id, 'created_at' => $now,
        ];
        DB::table('task_record_links')->insert($link);
        $this->assertSame(['label' => 'Acme'], json_decode(DB::table('task_record_links')->value('display_snapshot'), true));
        $this->assertConstraintRejects(fn () => DB::table('task_record_links')->insert([
            ...$link, 'id' => (string) Str::uuid7(),
        ]), 'task_record_links_record_unique');
        $this->assertConstraintRejects(fn () => DB::table('task_record_links')->insert([
            ...$link, 'id' => (string) Str::uuid7(), 'record_id' => (string) Str::uuid7(),
            'created_by' => $beta['admin']->id,
        ]), 'task_record_links_creator_foreign');
        $this->assertConstraintRejects(fn () => DB::table('task_record_links')->insert([
            ...$link, 'id' => (string) Str::uuid7(), 'task_id' => $betaTask,
            'record_id' => (string) Str::uuid7(),
        ]), 'task_record_links_task_foreign');
    }

    /** @param array{org: object, admin: object} $tenant @return array{string,string} */
    private function insertBoardAndTask(array $tenant, mixed $now): array
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
        $taskId = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $taskId, 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'group_id' => $groupId, 'parent_task_id' => null, 'number' => 'T-'.Str::random(16), 'title' => 'Task',
            'description' => null, 'description_plain' => null, 'owner_user_id' => $tenant['admin']->id,
            'status_option_id' => $statusId, 'priority_option_id' => null, 'start_on' => null, 'due_on' => null,
            'due_revision' => 0, 'completed_at' => null, 'completed_by' => null, 'cancelled_at' => null,
            'cancelled_by' => null, 'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'position' => 0, 'version' => 1, 'detail_version' => 1, 'archived_with_parent_id' => null,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$boardId, $taskId];
    }

    /** @param array{org: object} $tenant @return array<string,mixed> */
    private function identityRow(array $tenant, string $boardId, string $taskId, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid7(),
            'organization_id' => $tenant['org']->id,
            'board_id' => $boardId,
            'task_id' => $taskId,
        ];
    }

    /** @param array{org: object, admin: object} $tenant */
    private function insertAttachment(array $tenant, string $taskId, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('attachments')->insert([
            'id' => $id, 'organization_id' => $tenant['org']->id, 'attachable_type' => 'task',
            'attachable_id' => $taskId, 'disk' => 'local', 'path' => "tests/{$id}.txt",
            'original_name' => 'candidate.txt', 'mime' => 'text/plain', 'size_bytes' => 4,
            'checksum' => str_repeat('a', 64), 'uploaded_by_user_id' => $tenant['admin']->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
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

    private function assertQueryRejects(Closure $write): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
