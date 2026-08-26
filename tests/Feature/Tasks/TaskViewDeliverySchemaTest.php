<?php

namespace Tests\Feature\Tasks;

use App\Models\Notification;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskViewDeliverySchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_view_delivery_and_import_storage_enforces_identity_and_idempotency(): void
    {
        $migration = require database_path('migrations/2026_08_25_173000_create_task_view_and_delivery_tables.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('task_views'));
            $this->assertFalse(Schema::hasColumn('notifications', 'delivery_key'));
        } finally {
            $migration->up();
        }

        foreach (['task_views', 'task_view_preferences', 'task_overdue_occurrences',
            'task_due_reminder_occurrences', 'task_notification_intents', 'task_import_sessions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        $this->assertTrue(Schema::hasColumn('notifications', 'delivery_key'));
        foreach (['title', 'body', 'data', 'payload', 'delivered_notification_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('task_notification_intents', $column));
        }

        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $alphaOther = $this->userWithRole($alpha, 'Alpha Viewer');
        $now = now();
        [$alphaBoard, $alphaTask] = $this->insertBoardAndTask($alpha, $now);
        [$betaBoard, $betaTask] = $this->insertBoardAndTask($beta, $now);

        $main = $this->viewRow($alpha, $alphaBoard, null, 'shared', 'Main', 0, $now, true);
        $personal = $this->viewRow($alpha, $alphaBoard, $alpha['admin']->id, 'personal', 'My work', 0, $now);
        DB::table('task_views')->insert($main);
        DB::table('task_views')->insert($personal);
        DB::table('task_views')->insert($this->viewRow($alpha, $alphaBoard, $alphaOther->id, 'personal', 'My work', 0, $now));
        $this->assertSame(1, DB::table('task_views')->find($main['id'])->version);
        $this->rejectsAny(fn () => DB::table('task_views')->where('id', $main['id'])
            ->update(['is_permanent_main' => false]));
        $this->rejectsAny(fn () => DB::table('task_views')->where('id', $personal['id'])
            ->update(['is_permanent_main' => true]));
        $this->rejectsAny(fn () => DB::table('task_views')->where('id', $main['id'])->delete());
        $this->rejects(fn () => DB::table('task_views')->insert([
            ...$main, 'id' => (string) Str::uuid7(), 'name' => 'Main copy', 'position' => 1,
        ]), 'task_views_board_main_unique');
        $this->rejects(fn () => DB::table('task_views')->insert([
            ...$personal, 'id' => (string) Str::uuid7(), 'position' => 1,
        ]), 'task_views_scope_name_unique');
        $this->rejects(fn () => DB::table('task_views')->insert([
            ...$personal, 'id' => (string) Str::uuid7(), 'name' => 'Another',
        ]), 'task_views_scope_position_unique');
        $this->rejects(fn () => DB::table('task_views')->insert([
            ...$personal, 'id' => (string) Str::uuid7(), 'name' => 'Wrong owner', 'position' => 2,
            'owner_user_id' => $beta['admin']->id,
        ]), 'task_views_owner_foreign');
        $this->rejectsAny(fn () => DB::table('task_views')->insert([
            ...$personal, 'id' => (string) Str::uuid7(), 'name' => 'Missing owner', 'position' => 2,
            'owner_user_id' => null,
        ]));

        $preference = [
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'view_id' => $personal['id'], 'user_id' => $alpha['admin']->id, 'collapsed_group_ids' => json_encode([]),
            'density' => 'compact', 'is_last_active' => true, 'scroll_left' => 120, 'scroll_top' => 45,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('task_view_preferences')->insert($preference);
        $this->rejects(fn () => DB::table('task_view_preferences')->insert([
            ...$preference, 'id' => (string) Str::uuid7(),
        ]), 'task_view_preferences_view_user_unique');
        $this->rejects(fn () => DB::table('task_view_preferences')->insert([
            ...$preference, 'id' => (string) Str::uuid7(), 'view_id' => $this->insertView($beta, $betaBoard, $now),
            'is_last_active' => false,
        ]), 'task_view_preferences_view_foreign');
        $this->rejects(fn () => DB::table('task_view_preferences')->insert([
            ...$preference, 'id' => (string) Str::uuid7(), 'user_id' => $beta['admin']->id,
        ]), 'task_view_preferences_user_foreign');

        $overdue = [...$this->taskIdentity($alpha, $alphaBoard, $alphaTask), 'due_revision' => 3,
            'due_on' => '2026-08-20', 'created_at' => $now];
        DB::table('task_overdue_occurrences')->insert($overdue);
        $this->rejects(fn () => DB::table('task_overdue_occurrences')->insert([
            ...$overdue, 'id' => (string) Str::uuid7(),
        ]), 'task_overdue_occurrences_task_revision_unique');
        $this->rejects(fn () => DB::table('task_overdue_occurrences')->insert([
            ...$overdue, 'id' => (string) Str::uuid7(), 'task_id' => $betaTask, 'due_revision' => 4,
        ]), 'task_overdue_occurrences_task_foreign');

        $reminder = [...$this->taskIdentity($alpha, $alphaBoard, $alphaTask),
            'recipient_user_id' => $alpha['admin']->id, 'due_revision' => 3, 'lead_hours' => 24,
            'due_on' => '2026-08-20', 'created_at' => $now];
        DB::table('task_due_reminder_occurrences')->insert($reminder);
        $this->rejects(fn () => DB::table('task_due_reminder_occurrences')->insert([
            ...$reminder, 'id' => (string) Str::uuid7(),
        ]), 'task_due_reminders_occurrence_unique');
        $this->rejects(fn () => DB::table('task_due_reminder_occurrences')->insert([
            ...$reminder, 'id' => (string) Str::uuid7(), 'recipient_user_id' => $beta['admin']->id,
        ]), 'task_due_reminders_recipient_foreign');

        $intent = [...$this->taskIdentity($alpha, $alphaBoard, $alphaTask),
            'recipient_user_id' => $alpha['admin']->id, 'kind' => 'task.overdue',
            'source_occurrence_key' => $overdue['id'], 'status' => 'pending', 'available_at' => $now,
            'claim_token' => null, 'claimed_until' => null, 'attempts' => 0, 'last_error_code' => null,
            'created_at' => $now, 'updated_at' => $now];
        DB::table('task_notification_intents')->insert($intent);
        $this->rejects(fn () => DB::table('task_notification_intents')->insert([
            ...$intent, 'id' => (string) Str::uuid7(),
        ]), 'task_notification_intents_recipient_occurrence_unique');
        $this->rejects(fn () => DB::table('task_notification_intents')->insert([
            ...$intent, 'id' => (string) Str::uuid7(), 'source_occurrence_key' => (string) Str::uuid7(),
            'recipient_user_id' => $beta['admin']->id,
        ]), 'task_notification_intents_recipient_foreign');
        $this->rejectsAny(fn () => DB::table('task_notification_intents')->insert([
            ...$intent, 'id' => (string) Str::uuid7(), 'source_occurrence_key' => (string) Str::uuid7(),
            'status' => 'processing',
        ]));
        DB::table('task_notification_intents')->insert([
            ...$intent, 'id' => (string) Str::uuid7(), 'source_occurrence_key' => (string) Str::uuid7(),
            'status' => 'processing', 'claim_token' => (string) Str::uuid7(),
            'claimed_until' => $now->copy()->addMinutes(5),
        ]);

        $deliveryKey = (string) Str::uuid7();
        $notification = [
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id, 'source' => 'task', 'delivery_key' => $deliveryKey,
            'title' => 'Task changed', 'body' => null, 'subject_type' => 'task', 'subject_id' => $alphaTask,
            'automation_id' => null, 'event_id' => null, 'read_at' => null, 'created_at' => $now,
        ];
        DB::table('notifications')->insert($notification);
        $this->rejects(fn () => DB::table('notifications')->insert([
            ...$notification, 'id' => (string) Str::uuid7(),
        ]), 'notifications_delivery_unique');
        DB::table('notifications')->insert([...$notification, 'id' => (string) Str::uuid7(), 'delivery_key' => null]);
        DB::table('notifications')->insert([...$notification, 'id' => (string) Str::uuid7(), 'delivery_key' => null]);
        $this->assertSame($deliveryKey, (new Notification(['delivery_key' => $deliveryKey]))->delivery_key);

        $import = [
            'id' => (string) Str::uuid7(), 'organization_id' => $alpha['org']->id, 'board_id' => $alphaBoard,
            'active_view_id' => $main['id'], 'actor_user_id' => $alpha['admin']->id,
            'token_hash' => hash('sha256', 'token-alpha'), 'nonce' => (string) Str::uuid7(),
            'disk' => 'local', 'path' => 'task-imports/staged.csv', 'bytes' => 128,
            'sha256' => hash('sha256', 'file-alpha'), 'normalized_mapping' => json_encode(['title' => 0]),
            'structure_version' => 7, 'expires_at' => $now->copy()->addMinutes(30), 'consumed_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('task_import_sessions')->insert($import);
        $this->rejects(fn () => DB::table('task_import_sessions')->insert([
            ...$import, 'id' => (string) Str::uuid7(), 'nonce' => (string) Str::uuid7(),
        ]), 'task_import_sessions_token_unique');
        $this->rejects(fn () => DB::table('task_import_sessions')->insert([
            ...$import, 'id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', 'token-beta'),
        ]), 'task_import_sessions_nonce_unique');
        $this->rejects(fn () => DB::table('task_import_sessions')->insert([
            ...$import, 'id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', 'wrong-view'),
            'nonce' => (string) Str::uuid7(), 'active_view_id' => $this->insertView($beta, $betaBoard, $now),
        ]), 'task_import_sessions_view_foreign');
        $this->rejects(fn () => DB::table('task_import_sessions')->insert([
            ...$import, 'id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', 'wrong-user'),
            'nonce' => (string) Str::uuid7(), 'actor_user_id' => $beta['admin']->id,
        ]), 'task_import_sessions_actor_foreign');
    }

    /** @param array{org: object, admin: object} $tenant @return array{string,string} */
    private function insertBoardAndTask(array $tenant, mixed $now): array
    {
        $boardId = (string) Str::uuid7();
        DB::table('task_boards')->insert([
            'id' => $boardId, 'organization_id' => $tenant['org']->id, 'company_id' => null,
            'franchise_id' => null, 'folder_id' => null, 'name' => "Board {$boardId}", 'description' => null,
            'visibility' => 'scope', 'position' => random_int(1, 999999), 'created_by' => $tenant['admin']->id,
            'updated_by' => $tenant['admin']->id, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
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
            'status_option_id' => $statusId, 'priority_option_id' => null, 'start_on' => null, 'due_on' => '2026-08-20',
            'due_revision' => 3, 'completed_at' => null, 'completed_by' => null, 'cancelled_at' => null,
            'cancelled_by' => null, 'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
            'position' => 0, 'version' => 1, 'detail_version' => 1, 'archived_with_parent_id' => null,
            'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$boardId, $taskId];
    }

    /** @param array{org: object} $tenant @return array<string,mixed> */
    private function viewRow(array $tenant, string $boardId, ?string $ownerId, string $visibility, string $name, int $position, mixed $now, bool $isMain = false): array
    {
        return [
            'id' => (string) Str::uuid7(), 'organization_id' => $tenant['org']->id, 'board_id' => $boardId,
            'owner_user_id' => $ownerId, 'visibility' => $visibility, 'name' => $name,
            'is_locked' => $isMain, 'is_permanent_main' => $isMain,
            'configuration' => json_encode(['schema' => 1, 'presentation' => 'table', 'filters' => [],
                'sorts' => [], 'hidden_columns' => [], 'column_order' => [], 'column_widths' => [],
                'group_by' => null, 'search' => '']),
            'version' => 1, 'position' => $position, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** @param array{org: object} $tenant */
    private function insertView(array $tenant, string $boardId, mixed $now): string
    {
        $view = $this->viewRow($tenant, $boardId, null, 'shared', 'Other '.Str::random(8), random_int(10, 999999), $now);
        DB::table('task_views')->insert($view);

        return $view['id'];
    }

    /** @param array{org: object} $tenant @return array<string,mixed> */
    private function taskIdentity(array $tenant, string $boardId, string $taskId): array
    {
        return ['id' => (string) Str::uuid7(), 'organization_id' => $tenant['org']->id,
            'board_id' => $boardId, 'task_id' => $taskId];
    }

    private function rejects(Closure $write, string $constraint): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    private function rejectsAny(Closure $write): void
    {
        try {
            $write();
            $this->fail('Expected MySQL to reject the invalid row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
