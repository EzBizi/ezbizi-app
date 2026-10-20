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

    /**
     * The example moved from Marketing to Newsletter on 2026-09-09.
     *
     * Marketing went live, which turned this red — the third module to do that
     * to this test after Web Management. The lesson is in the choice of
     * replacement rather than in the fix: **an example should be a case that is
     * SETTLED, not merely one that is true today.** Newsletter's placeholder is
     * argued in the registry as the permanent answer — it keeps its own
     * dashboard inside its tab, and a second summary a click away would drift
     * from it — so it is not a queue entry waiting to be built like Marketing's
     * was.
     *
     * Marketing's own "no longer coming soon" assertion lives with the glance,
     * in MarketingGlanceTest, rather than being restated here.
     */
    public function test_a_coming_soon_glance_has_no_chart_presentations(): void
    {
        $user = $this->userWithPermissions(['newsletter.view']);
        $payload = app(GlanceRegistry::class)->find('newsletter', $user)
            ->resolve($user, DashboardPeriod::for($user), []);
        $array = $payload->toArray();
        $this->assertTrue($array['coming_soon']);
        $this->assertSame([], $array['allowed_presentations']);
        $this->assertSame('text', $array['presentation']);
        /*
         * Not a literal from the module's copy.
         *
         * This pinned Marketing's exact words "compose is live", so correcting
         * a sentence that had gone stale turned THIS test red — a test in
         * Dashboard defending wrong copy in another module, and the reason the
         * correction sat unmade through a release. The intent here is that a
         * coming-soon glance resolves to text with no chart and a real
         * sentence; the specific wording was never the subject.
         */
        $this->assertNotSame('', trim((string) $array['data']['sentence']));
    }

    /**
     * Web Management held the coming-soon example above until 2026-08-28, and
     * its placeholder sentence promised a website editor. What shipped reads a
     * company's registered hosts instead, so the box counts them — and the
     * assertion that matters is that it no longer claims to be coming.
     */
    public function test_web_management_is_a_real_glance_now(): void
    {
        $user = $this->userWithPermissions(['web_management.view']);
        $array = app(GlanceRegistry::class)->find('web_management', $user)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray();

        $this->assertFalse($array['coming_soon']);
        $this->assertTrue($array['data']['empty']);

        /*
         * Was `assertStringContainsString('No websites', …)`, which is Web
         * Management's copy pinned from Dashboard's file — the same coupling
         * the sibling test above already had to unpick after it held
         * Marketing's stale wording through a release.
         *
         * The docblock says what this test is about: that the box no longer
         * claims to be coming. `coming_soon` false and `empty` true are that
         * claim. The wording of the sentence never was, and a wording change
         * Web Management is entitled to make should not turn red in a file
         * they do not own.
         *
         * Not caught by the deploy desk's sweep for "in reach" and "yet"
         * (2026-09-09) because this sentence uses neither — which is the
         * argument for fixing the COUPLING rather than the strings.
         */
        $this->assertNotSame('', trim((string) $array['data']['sentence']));
    }

    public function test_dashboard_is_not_a_glance(): void
    {
        $this->assertNull(app(GlanceRegistry::class)->find('dashboard', $this->alpha['admin']));
    }

    /**
     * Every sidebar tab has a box.
     *
     * (A `glanceExemptBecause` field once existed for the A1Leads GBP tab,
     * retired 2026-10-20.)
     */
    public function test_every_sidebar_tab_except_dashboard_has_a_glance(): void
    {
        $registry = app(\App\Support\Modules\ModuleRegistry::class);
        $tabs = collect($registry->all())
            ->filter(fn ($m) => $m->rolesGroup === \App\Support\Modules\ModuleDefinition::ROLES_TAB
                && $m->sidebar
                && $m->key !== 'dashboard')
            ->keys()->sort()->values()->all();
        $glances = collect(app(GlanceRegistry::class)->all())->map(fn ($g) => $g->key())->sort()->values()->all();
        $this->assertSame($tabs, $glances);
    }

    /**
     * The other half of the box, and it had no test at all.
     *
     * `dashboardPanelIcon` ends in `?? Icons.insights_outlined`, so a module
     * the map has not heard of is not an error — it is a generic chart glyph
     * on every customer's dashboard, drawn beside a correct title, with
     * nothing red anywhere. That is not hypothetical: business_profile,
     * leads, monitoring, reviews and tasks each shipped that way and were
     * found by somebody looking at the screen (fixed in 0f0c278).
     *
     * The list lives in Dart and the modules live here, so the assertion has
     * to read the file. Exact equality in both directions, the same shape as
     * the glance assertion above: a module with no icon fails, and a key
     * outlasting the module it was drawn for fails too — `tasks` sat in that
     * map after the module was deleted.
     */
    public function test_every_sidebar_tab_has_a_dashboard_icon(): void
    {
        $source = file_get_contents(base_path('client/lib/core/dashboard_panels.dart'));

        // A module with no glance draws no panel, so it needs no panel icon.
        // Its SIDEBAR icon is a different map in a different file
        // (`module_screens.dart`) and is not what this asserts.

        // The dot is in the class deliberately, and it is the whole reason the
        // first version of this guard was decorative in one direction. UI/UX
        // found it by RUNNING the regex rather than reading it (3d3b1f61):
        // `[a-z_]+` cannot match `hr.headcount`, so a dotted key was invisible
        // here while being live at runtime — and a dotted key is precisely the
        // shape the rule forbids, since `dashboardPanelIcon` already falls
        // through `key.split('.').first` to the parent's icon and so never
        // needs its own entry. The one thing the assertion exists to catch was
        // the one thing it could not see.
        preg_match_all("/'([a-z0-9_.]+)':\s*Icons\./", $source, $found);
        $drawn = collect($found[1])->sort()->values()->all();

        // The control: a regex that stopped matching would make every diff
        // below empty and this test green on an empty map.
        $this->assertContains('hr', $drawn, 'The icon map was not read at all.');

        $tabs = collect(app(\App\Support\Modules\ModuleRegistry::class)->all())
            ->filter(fn ($m) => $m->rolesGroup === \App\Support\Modules\ModuleDefinition::ROLES_TAB
                && $m->sidebar
                && $m->key !== 'dashboard')
            ->keys()->sort()->values()->all();

        $this->assertSame($tabs, $drawn);
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

    public function defaultSize(): string
    {
        return 'medium';
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
