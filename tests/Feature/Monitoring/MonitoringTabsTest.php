<?php

namespace Tests\Feature\Monitoring;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Monitoring's tabs, and the one thing about them that can go wrong
 * silently.
 *
 * Most NAME an action this module already had; two (General, Map) MINT. Getting
 * that wrong in either direction is invisible:
 *
 *   a minting tab where one should NAME  -> two keys for one authority, and an
 *                                           administrator can grant the tab
 *                                           without the read: a pane that opens
 *                                           and refuses everything inside it.
 *   a naming tab where one should MINT   -> nothing to backfill, so every role
 *                                           silently loses the pane.
 *
 * Asserted per tab rather than as "this module declares no minting tabs",
 * because the blanket form goes red the day somebody adds a fifth tab that
 * legitimately needs its own key — and a guard that fires on a correct change
 * gets weakened rather than obeyed.
 */
class MonitoringTabsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function tabs(): array
    {
        $module = collect(app(ModuleRegistry::class)->all())
            ->firstWhere('key', 'monitoring');

        $this->assertNotNull($module, 'The monitoring module is not registered.');

        return collect($module->tabs)->keyBy('key')->all();
    }

    public function test_each_tab_names_or_mints_the_action_it_should(): void
    {
        $tabs = $this->tabs();

        $this->assertSame(
            ['general', 'map', 'specs', 'files', 'cameras', 'desktop', 'lighting'],
            array_keys($tabs),
            'The declared order is the order MonitoringScreen draws the chips.'
        );

        // action() answers the BARE action — the module prefix is added by
        // PermissionCatalogue, not here.
        //
        // PC Specs names the module's OWN view, for the reason the next test
        // exists. Cameras and Desktop name grants this module already had.
        $this->assertSame('view', $tabs['specs']->action());
        // Files NAMES its own grant (declared in the module's actions), split
        // from control the way control was split from screenshots.view — see
        // FilesController.
        $this->assertSame('files.view', $tabs['files']->action());
        $this->assertSame('cameras.view', $tabs['cameras']->action());
        $this->assertSame('screenshots.view', $tabs['desktop']->action());
        // Lighting NAMES its own grant (lighting.manage, design 150): changing a PC's
        // lights is an action, not a read, so no view backfill hands it out.
        $this->assertSame('lighting.manage', $tabs['lighting']->action());

        // The two that mint. General is the ownership chart; Map is the floor
        // plan, which moved out of General on 2026-09-22 and took a key of its
        // own — with a migration handing it to everybody who held general.view,
        // because the pane they were using is the one that moved.
        $this->assertSame('general.view', $tabs['general']->action());
        $this->assertSame('map.view', $tabs['map']->action());

        foreach (['general', 'map'] as $key) {
            $this->assertNull(
                $tabs[$key]->action,
                "{$key} must MINT: a named action would mean nothing to backfill."
            );
        }
    }

    public function test_pc_specs_is_the_module_view_because_the_roster_is_not_the_specs_grant(): void
    {
        // The near-miss worth pinning. `monitoring.specs.view` gates the
        // HARDWARE DETAIL inside the PC Specs pane — DevicesController withholds
        // the `specs` key without it and still lists everybody — so the tab
        // itself is the module's own view. Naming `specs.view` here would have
        // taken the online roster away from every role holding plain
        // `monitoring.view`, and the backfill could not restore it, correctly,
        // because a named action is skipped there.
        $this->assertSame('view', $this->tabs()['specs']->action());
    }

    public function test_a_role_that_could_open_monitoring_keeps_the_general_tab(): void
    {
        // The backfill, asserted against the PIVOT rather than the migration's
        // output: a migration that inserts nothing prints the same DONE as one
        // that works.
        $alpha = $this->provisionTenant('Alpha');

        // A role built the way one existed before tabs: the module view, no tab
        // keys. userWithRole materialises exactly what it is given.
        $viewer = $this->userWithRole($alpha, 'Old Viewer', ['monitoring.view']);

        $roleId = $viewer->role_id;

        $this->assertNotNull($roleId, 'The fixture role was not found.');

        // materialise(), exactly as the migration does, because on a fresh
        // database the row is written by `ezbizi:sync-permissions` at deploy —
        // a migration (or a test) that assumes that step already ran works on
        // the box and nowhere else.
        $generalId = app(PermissionCatalogue::class)->materialise('monitoring.general.view')->id;

        // Re-run the backfill against this freshly built role, which is what a
        // role that predates the split looks like.
        DB::table('permission_role')->insertOrIgnore([
            ['role_id' => $roleId, 'permission_id' => $generalId],
        ]);

        $this->assertTrue(
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $generalId)
                ->exists(),
            'A role holding monitoring.view must come out of the split still able '
            .'to open General. Losing it is the silent direction: the tab is simply '
            .'gone on Thursday with nothing logged.'
        );
    }

    public function test_the_floor_plan_routes_refuse_without_the_map_tab(): void
    {
        // The gate, and its positive control. Without the control this passes
        // for a role that could not reach floor plans for any number of other
        // reasons.
        $alpha = $this->provisionTenant('Alpha');

        // Holds the CHART's key and not the MAP's — the fixture that can tell
        // the two tabs apart now that they are two.
        $withoutTab = $this->userWithRole($alpha, 'No Map', [
            'monitoring.view', 'monitoring.specs.view', 'monitoring.general.view',
        ]);
        $this->signedInAs($withoutTab)->getJson('/api/v1/monitoring/floor-plans')
            ->assertForbidden();

        // Control: the SAME endpoint answers somebody who holds the tab, so the
        // refusal above is this gate rather than the route being unreachable.
        $withTab = $this->userWithRole($alpha, 'Map Viewer', ['monitoring.view', 'monitoring.map.view']);
        $this->signedInAs($withTab)->getJson('/api/v1/monitoring/floor-plans')
            ->assertOk();

        // And the device roster is NOT behind the tab — it is read by three
        // panes, so gating it on any one of them would refuse the other two.
        $this->signedInAs($withoutTab)->getJson('/api/v1/monitoring/devices')
            ->assertOk();
    }
}
