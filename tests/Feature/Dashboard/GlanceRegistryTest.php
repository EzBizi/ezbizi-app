<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Declared dashboard glances (spec §4, §9).
 *
 * One box per reachable sidebar module. Task 1 registers only the five
 * coming-soon modules; permission and ModuleAvailability still gate them.
 */
class GlanceRegistryTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /**
     * Somebody holding exactly these permissions, assigned to the franchise.
     *
     * Same shape as DashboardTest's Jobs-only helper: sync the platform
     * permission rows onto a new role, then assign the person to F1.
     *
     * @param  string[]  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        return $this->inTenant(function () use ($permissionKeys) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Limited', 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', $permissionKeys)->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inventory_view_alone_sees_only_the_inventory_glance(): void
    {
        $user = $this->userWithPermissions(['inventory.view']);
        $keys = array_map(fn ($g) => $g->key(), app(GlanceRegistry::class)->availableTo($user));
        $this->assertSame(['inventory'], $keys);
    }

    public function test_a_coming_soon_glance_has_no_chart_presentations(): void
    {
        $user = $this->userWithPermissions(['inventory.view']);
        $payload = app(GlanceRegistry::class)->find('inventory', $user)
            ->resolve($user, DashboardPeriod::for($user), []);
        $array = $payload->toArray();
        $this->assertTrue($array['coming_soon']);
        $this->assertSame([], $array['allowed_presentations']);
        $this->assertSame('text', $array['presentation']);
        $this->assertStringContainsString('register is not here yet', $array['data']['sentence']);
    }

    public function test_dashboard_is_not_a_glance(): void
    {
        $this->assertNull(app(GlanceRegistry::class)->find('dashboard', $this->alpha['admin']));
    }
}
