<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Activity and API activity moved from Settings to Monitoring (Jose, 2026-11-25). */
class MonitoringActivityTabsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_11_25_140712_grant_monitoring_activity_tabs_to_roles_holding_settings_activity.php';

    private function reachable(): bool
    {
        return collect($this->getJson('/api/v1/me')->assertOk()->json('data.modules'))
            ->firstWhere('key', 'monitoring')['reachable'];
    }

    private function keysOf(string $roleId): array
    {
        return DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_id', $roleId)->pluck('key')->all();
    }

    public function test_activity_alone_reads_the_audit_log_and_not_the_devices(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Auditor', ['monitoring.activity.view']);

        $this->assertNotContains('monitoring.view', $this->keysOf($user->role_id));
        $this->signedInAs($user)->getJson('/api/v1/audit-events')->assertOk();
        $this->getJson('/api/v1/monitoring/devices')->assertForbidden();
        $this->assertTrue($this->reachable());
    }

    public function test_api_activity_alone_reads_everyones_key_activity_and_not_the_devices(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Keys', ['monitoring.api_activity.view']);

        $this->assertNotContains('monitoring.view', $this->keysOf($user->role_id));
        \App\Models\AuditEvent::create([
            'organization_id' => $t['org']->id, 'actor_type' => 'user', 'actor_id' => $t['admin']->getKey(),
            'actor_label' => 'Admin', 'action' => 'api_key.request', 'target_label' => 'x', 'occurred_at' => now(),
        ]);
        $plain = $this->userWithRole($t, 'Plain', ['api_keys.view']);
        $this->assertSame([], $this->signedInAs($plain)->getJson('/api/v1/api-keys/activity')->assertOk()->json('activity'), 'control: own keys only');
        $this->assertCount(1, $this->signedInAs($user)->getJson('/api/v1/api-keys/activity')->assertOk()->json('activity'));
        $this->signedInAs($user)->getJson('/api/v1/audit-events')->assertForbidden();
        $this->getJson('/api/v1/monitoring/devices')->assertForbidden();
        $this->assertTrue($this->reachable());
    }

    public function test_neither_tab_means_monitoring_is_not_reachable(): void
    {
        $t = $this->provisionTenant();
        $this->signedInAs($this->userWithRole($t, 'Plain', ['organizations.view']));

        $this->getJson('/api/v1/audit-events')->assertForbidden();
        $this->assertFalse($this->reachable());
    }

    public function test_the_migration_carries_yesterdays_holders_over(): void
    {
        $t = $this->provisionTenant();
        $auditor = $this->userWithRole($t, 'Auditor', ['audit.view'])->role_id;
        $keys = $this->userWithRole($t, 'Keys', ['organizations.view'])->role_id;
        $other = $this->userWithRole($t, 'Other', ['organizations.view'])->role_id;

        $old = Permission::create(['key' => 'organizations.api_activity.view', 'module' => 'organizations', 'action' => 'api_activity.view', 'label' => 'API activity']);
        DB::table('permission_role')->insert(['role_id' => $keys, 'permission_id' => $old->id]);

        (require base_path(self::FILE))->up();

        $this->assertContains('monitoring.activity.view', $this->keysOf($auditor));
        $this->assertContains('audit.view', $this->keysOf($auditor));
        $this->assertContains('monitoring.api_activity.view', $this->keysOf($keys));
        $this->assertNotContains('organizations.api_activity.view', $this->keysOf($keys));
        $this->assertNull(Permission::query()->where('key', 'organizations.api_activity.view')->first());
        $this->assertNotContains('monitoring.activity.view', $this->keysOf($other));
        $this->assertNotContains('monitoring.api_activity.view', $this->keysOf($other));
    }
}
