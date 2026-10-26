<?php

namespace Tests\Feature\Inspection;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One tab at a time, which is what Jose asked for on 2026-09-17.
 *
 * > "i should be able to grant access by sub tab as well, so for example i want
 * > them to see their work hours, but not the onboarding information, etc — so
 * > this applies to every tab and it's sub tabs"
 *
 * For Inspection that is somebody who files inspections all day and has no
 * business reading the whole branch's filed reports.
 *
 * ── The positive control comes FIRST, and that is not stylistic ──
 *
 * The Settings module lost time to a suite of 403 assertions that all passed
 * while the feature was broken: a role holding the tab was refused by the
 * PLATFORM layer, before any permission was consulted, and every negative case
 * agreed with it. A wall of "this is refused" cannot tell a working gate from a
 * module switched off, a missing subscription, or a route that does not exist.
 *
 * So every pair here is CAN-then-cannot, and the can is written first. If the
 * first assertion in a pair fails, the second proves nothing and should not be
 * read.
 */
class InspectionTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── Generated Reports ────────────────────────────────────────

    public function test_a_role_holding_the_reports_tab_can_reach_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Office', ['inspection.view', 'inspection.reports.view']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/reports')->assertOk();
    }

    public function test_the_same_role_without_that_tab_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Tech', ['inspection.view']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/reports')->assertForbidden();
    }

    // ── New Inspection ───────────────────────────────────────────

    public function test_a_role_holding_the_new_tab_can_reach_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.create', 'inspection.new.view']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/search?q=x')->assertOk();
    }

    public function test_the_same_role_without_the_new_tab_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.create']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/search?q=x')->assertForbidden();
    }

    // ── Builder ──────────────────────────────────────────────────

    public function test_a_role_holding_the_builder_tab_can_reach_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Manager', ['inspection.view', 'inspection.edit', 'inspection.builder.view']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/catalogue')->assertOk();
    }

    public function test_the_same_role_without_the_builder_tab_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Manager', ['inspection.view', 'inspection.edit']);

        $this->signedInAs($user)->getJson('/api/v1/inspections/catalogue')->assertForbidden();
    }

    /**
     * ONE TAB DOES NOT CARRY ANOTHER, which is the whole point of the feature.
     *
     * Holding Generated Reports must not open the Builder. Without this, a
     * single grant that happened to satisfy every route would pass all six
     * tests above and grant nothing separately.
     */
    public function test_holding_one_tab_does_not_open_another(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Office', [
            'inspection.view', 'inspection.edit', 'inspection.reports.view',
        ]);

        $this->signedInAs($user)->getJson('/api/v1/inspections/reports')->assertOk();
        $this->signedInAs($user)->getJson('/api/v1/inspections/catalogue')->assertForbidden();
    }

    /**
     * The labels are the words the tab bar shows.
     *
     * An administrator ticks a box in the roles editor and then looks for a tab
     * with that name. If the two disagree nothing errors anywhere — they simply
     * cannot find what they just granted. Pinned against the literal strings in
     * `inspection_screen.dart`, which is the only place the user reads them.
     */
    public function test_the_declared_tabs_are_the_ones_the_screen_draws(): void
    {
        $tabs = app(ModuleRegistry::class)->all()['inspection']->tabs ?? [];

        $byKey = [];
        foreach ($tabs as $tab) {
            $byKey[$tab->key] = $tab->label;
        }

        $this->assertSame(
            ['new' => 'New Inspection', 'reports' => 'Generated Reports', 'builder' => 'Builder', 'testing' => 'Testing'],
            $byKey,
        );
    }
}
