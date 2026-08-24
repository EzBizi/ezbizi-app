<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\ComingSoonGlance;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Declared dashboard glances (spec §4, §9).
 *
 * One box per reachable sidebar module. Permission and ModuleAvailability
 * gate what availableTo returns; all() is the unfiltered declared set.
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
        $user = $this->userWithPermissions(['services.view']);
        $payload = app(GlanceRegistry::class)->find('services', $user)
            ->resolve($user, DashboardPeriod::for($user), []);
        $array = $payload->toArray();
        $this->assertTrue($array['coming_soon']);
        $this->assertSame([], $array['allowed_presentations']);
        $this->assertSame('text', $array['presentation']);
        $this->assertStringContainsString('catalogue is not here yet', $array['data']['sentence']);
    }

    public function test_dashboard_is_not_a_glance(): void
    {
        $this->assertNull(app(GlanceRegistry::class)->find('dashboard', $this->alpha['admin']));
    }

    public function test_every_sidebar_tab_except_dashboard_has_a_glance(): void
    {
        $registry = app(\App\Support\Modules\ModuleRegistry::class);
        $tabs = collect($registry->all())
            ->filter(fn ($m) => $m->rolesGroup === \App\Support\Modules\ModuleDefinition::ROLES_TAB && $m->key !== 'dashboard')
            ->keys()->sort()->values()->all();
        $glances = collect(app(GlanceRegistry::class)->all())->map(fn ($g) => $g->key())->sort()->values()->all();
        $this->assertSame($tabs, $glances);
    }

    public function test_a_live_glance_wins_when_coming_soon_still_lists_the_same_key(): void
    {
        $registry = new class(app(ModuleAvailability::class)) extends GlanceRegistry
        {
            protected function liveClasses(): array
            {
                return [LiveInventoryStubGlance::class];
            }
        };

        $glance = collect($registry->all())->first(
            fn (Glance $g) => $g->key() === 'inventory',
        );

        $this->assertNotNull($glance);
        $this->assertInstanceOf(LiveInventoryStubGlance::class, $glance);
        $this->assertNotInstanceOf(ComingSoonGlance::class, $glance);
    }
}

/** Minimal live glance used only to prove registry merge order. */
final class LiveInventoryStubGlance implements Glance
{
    public function key(): string
    {
        return 'inventory';
    }

    public function label(): string
    {
        return 'Inventory';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function allowedPresentations(): array
    {
        return ['pie'];
    }

    public function defaultPresentation(): string
    {
        return 'pie';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: ['empty' => true, 'sentences' => ['Live stub.']],
        );
    }
}
