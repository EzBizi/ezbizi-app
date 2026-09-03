<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `2026_09_03_093000_retire_tasks_module_records` — the rows Tasks left behind
 * in tables it did not own.
 *
 * ── Why this test exists at all ──────────────────────────────────
 *
 * `RefreshDatabase` runs every migration against an empty schema, so the suite
 * already proves this one does not ERROR. It proves nothing about whether it
 * deletes anything, because there was never a row for it to find. The only way
 * to know is to put the rows there first and run `up()` again — which is safe
 * precisely because the migration is idempotent.
 *
 * The distinction worth protecting is the one in the middle: an automation
 * TRIGGERED by a task event is deleted, and an automation that merely CALLS a
 * task action is disabled and kept. Getting that backwards would either leave
 * dead rules firing at nothing or silently destroy customer configuration that
 * Tasks never owned.
 */
class TasksRetirementTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_03_093000_retire_tasks_module_records.php';

    /** Re-run the migration's `up()` over rows this test seeded. */
    private function retire(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function automation(string $orgId, string $triggerKey, array $actions): string
    {
        $automationId = (string) Str::uuid();
        $versionId    = (string) Str::uuid();

        DB::table('automations')->insert([
            'id'              => $automationId,
            'organization_id' => $orgId,
            'scope_type'      => 'organization',
            'scope_id'        => $orgId,
            'name'            => "Rule for {$triggerKey}",
            'trigger_key'     => $triggerKey,
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('automation_versions')->insert([
            'id'              => $versionId,
            'organization_id' => $orgId,
            'automation_id'   => $automationId,
            'version'         => 1,
            'conditions'      => json_encode([]),
            'actions'         => json_encode($actions),
            'created_at'      => now(),
        ]);

        DB::table('automations')->where('id', $automationId)
            ->update(['active_version_id' => $versionId]);

        return $automationId;
    }

    public function test_it_removes_the_seven_permissions_and_the_grants_pointing_at_them(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $keys = [
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.comment',
            'tasks.delete', 'tasks.export', 'tasks.manage',
        ];

        $roleId = DB::table('roles')
            ->where('organization_id', $alpha['org']->id)
            ->where('name', 'Viewer')
            ->value('id');

        $seeded = [];

        foreach ($keys as $key) {
            $permissionId = (string) Str::uuid();
            $seeded[]     = $permissionId;
            [$module, $action] = explode('.', $key, 2);

            DB::table('permissions')->insert([
                'id'         => $permissionId,
                'key'        => $key,
                'module'     => $module,
                'action'     => $action,
                'label'      => ucfirst($action).' tasks',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id'       => $roleId,
            ]);
        }

        DB::table('platform_module_flags')->insert([
            'id'         => (string) Str::uuid(),
            'module_key' => 'tasks',
            'state'      => 'disabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(7, DB::table('permissions')->whereIn('key', $keys)->count());

        $this->retire();

        $this->assertSame(0, DB::table('permissions')->whereIn('key', $keys)->count());
        $this->assertSame(0, DB::table('permission_role')->whereIn('permission_id', $seeded)->count(),
            'the grants have to go first, or they point at nothing');
        $this->assertGreaterThan(0, DB::table('permission_role')->where('role_id', $roleId)->count(),
            'the role keeps the permission it legitimately holds');
        $this->assertSame(0, DB::table('platform_module_flags')->where('module_key', 'tasks')->count(),
            'a kill switch for a module that no longer exists is a switch with nothing behind it');

        // The permission this role legitimately holds is untouched — the
        // deletion is by key, not by role.
        $this->assertNotNull($viewer->fresh());
        $this->assertGreaterThan(0, DB::table('permissions')->where('key', 'customers.view')->count());
    }

    public function test_a_task_triggered_automation_is_deleted_but_one_that_calls_a_task_action_is_only_disabled(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $orgId = $alpha['org']->id;

        $dead = $this->automation($orgId, 'task.completed', [
            ['type' => 'notify', 'params' => []],
        ]);

        $caller = $this->automation($orgId, 'job.created', [
            ['type' => 'task.create', 'params' => []],
            ['type' => 'notify', 'params' => []],
        ]);

        $untouched = $this->automation($orgId, 'job.created', [
            ['type' => 'notify', 'params' => []],
        ]);

        $this->retire();

        $this->assertSame(0, DB::table('automations')->where('id', $dead)->count(),
            'nothing emits task.* any more, so this rule can never fire again');
        $this->assertSame(0, DB::table('automation_versions')->where('automation_id', $dead)->count(),
            'versions cascade with the automation');

        $callerRow = DB::table('automations')->where('id', $caller)->first();
        $this->assertNotNull($callerRow, 'this is the customer\'s rule, not a Tasks record');
        $this->assertSame(0, (int) $callerRow->is_active);
        $this->assertNotNull($callerRow->disabled_at);
        $this->assertStringContainsString('Tasks module was removed', $callerRow->disabled_reason);

        $untouchedRow = DB::table('automations')->where('id', $untouched)->first();
        $this->assertNotNull($untouchedRow);
        $this->assertSame(1, (int) $untouchedRow->is_active,
            'a rule that never mentioned Tasks must not be disabled by this');
    }

    public function test_it_clears_the_outbox_notifications_settings_sequence_and_preference(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $orgId = $alpha['org']->id;
        $admin = $alpha['admin'];

        DB::table('automation_events')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $orgId,
            'event_key'       => 'task.overdue',
            'subject_type'    => 'tasks',
            'subject_id'      => (string) Str::uuid(),
            'payload'         => json_encode([]),
            'occurred_at'     => now(),
            'available_at'    => now(),
        ]);

        DB::table('automation_events')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $orgId,
            'event_key'       => 'job.created',
            'subject_type'    => 'jobs',
            'subject_id'      => (string) Str::uuid(),
            'payload'         => json_encode([]),
            'occurred_at'     => now(),
            'available_at'    => now(),
        ]);

        foreach ([['task', 'tasks'], ['job', 'jobs']] as [$source, $morph]) {
            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'organization_id' => $orgId,
                'user_id'         => $admin->id,
                'source'          => $source,
                'title'           => "About a {$source}",
                'subject_type'    => $morph,
                'subject_id'      => (string) Str::uuid(),
                'created_at'      => now(),
            ]);
        }

        DB::table('attachments')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $orgId,
            'attachable_type' => 'task_updates',
            'attachable_id'   => (string) Str::uuid(),
            'disk'            => 'local',
            'path'            => 'a/b.png',
            'original_name'   => 'b.png',
            'mime'            => 'image/png',
            'size_bytes'      => 1,
            'checksum'        => str_repeat('0', 64),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        foreach (['numbering.task_prefix' => 'T-', 'notifications.task_enabled' => '1'] as $key => $value) {
            DB::table('settings')->insert([
                'id'              => (string) Str::uuid(),
                'organization_id' => $orgId,
                'scope_type'      => 'organization',
                'scope_id'        => $orgId,
                'key'             => $key,
                'value'           => json_encode($value),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        DB::table('record_sequences')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $orgId,
            'sequence_key'    => 'task',
            'next_value'      => 42,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('users')->where('id', $admin->id)->update([
            'preferences' => json_encode([
                'tasks.due_reminder_hours' => 48,
                'theme'                    => 'dark',
            ]),
        ]);

        $this->retire();

        $this->assertSame(0, DB::table('automation_events')->where('event_key', 'like', 'task.%')->count());
        $this->assertSame(1, DB::table('automation_events')->count(), 'the job event stays');

        $this->assertSame(0, DB::table('notifications')->where('source', 'task')->count());
        $this->assertSame(1, DB::table('notifications')->count(), 'the job notification stays');

        $this->assertSame(0, DB::table('attachments')->where('attachable_type', 'task_updates')->count());
        $this->assertSame(0, DB::table('settings')->where('key', 'like', '%task%')->count());
        $this->assertSame(0, DB::table('record_sequences')->where('sequence_key', 'task')->count());

        $preferences = json_decode(DB::table('users')->where('id', $admin->id)->value('preferences'), true);
        $this->assertArrayNotHasKey('tasks.due_reminder_hours', $preferences);
        $this->assertSame('dark', $preferences['theme'],
            'JSON_REMOVE on a NULL column yields NULL — every other preference must survive');
    }

    public function test_it_leaves_the_audit_log_alone(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        DB::table('audit_events')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $alpha['org']->id,
            'actor_type'      => 'user',
            'actor_id'        => $alpha['admin']->id,
            'actor_label'     => 'Admin',
            'action'          => 'task.created',
            'target_type'     => 'tasks',
            'target_id'       => (string) Str::uuid(),
            'occurred_at'     => now(),
        ]);

        $this->retire();

        $this->assertSame(1, DB::table('audit_events')->where('action', 'task.created')->count(),
            '§10 makes the log append-only; pruning it is the one thing it exists to make impossible');
    }
}
