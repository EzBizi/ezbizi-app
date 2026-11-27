<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reports' tabs are granted one at a time (Jose, 2026-09-17).
 *
 * > "i should be able to grant access by sub tab as well, so for example i want
 * > them to see their work hours, but not the onboarding information, etc — so
 * > this applies to every tab and it's sub tabs"
 *
 * ── WHAT A TAB GRANT MEANS IN THIS MODULE ───────────────────────
 *
 * In most modules it reads as "which pane opens". Here the panes are the same
 * capability aimed at different data, so it reads as WHICH FIGURES A ROLE MAY
 * SEE — a stronger claim, and the reason Experimental is the tab that earns
 * this. It names every person who answered a phone, how long they spent on it,
 * and how much of their work became jobs.
 *
 * ── THE POSITIVE CONTROL COMES FIRST, DELIBERATELY ──────────────
 *
 * Settings hit this in Human Resources: a role that HELD the tab was refused
 * anyway, by a platform-level check answering before any permission was
 * consulted. A suite of 403 assertions with no reachable case would have read
 * that as the feature working perfectly. So the first test here proves the
 * endpoint can be reached at all, and every refusal below is a claim about the
 * grant rather than about the endpoint being dead.
 */
class ReportsTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the control ──────────────────────────────────────────────

    public function test_a_role_holding_the_experimental_tab_reaches_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $analyst = $this->userWithRole($tenant, 'Analyst', [
            'reports.view',
            'reports.build',
            'reports.experimental.view',
        ]);

        /*
         * Unconfigured EXPLICITLY rather than assumed. The first run of this
         * test asserted "not connected" and got connected:true, because the
         * developer machine's .env carries real RingCentral credentials and
         * config reaches the test environment - so the control was one step
         * from making live API calls out of a permission test.
         */
        config([
            'experimental_reports.ringcentral.client_id' => null,
            'experimental_reports.ringcentral.client_secret' => null,
            'experimental_reports.ringcentral.jwt' => null,
        ]);

        // The pane then answers "not connected" — a 200 describing a state.
        // What matters is that the GATE let it through; a 403 here would make
        // every refusal below meaningless.
        $this->signedInAs($analyst)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertOk()
            ->assertJsonPath('connected', false);
    }

    // ── the carve-out Jose asked for ─────────────────────────────

    /**
     * SOMEBODY WHO BUILDS REPORTS IS NOT THEREBY OWED THE CALL PANE.
     *
     * This is the split that motivated the whole change. `reports.build` is a
     * strong grant — it is the one that already means organization-wide — and
     * before today it carried the Experimental pane with it. An analyst
     * building job reports now has to be given the personnel figures on
     * purpose.
     */
    public function test_reports_build_no_longer_carries_the_experimental_pane(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($tenant, 'Analyst', [
            'reports.view',
            'reports.build',
        ]);

        $this->signedInAs($builder)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();

        // And the recording behind a call is the same pane by another door.
        $this->signedInAs($builder)
            ->get('/api/v1/reports/experimental/calls/rec-1/recording')
            ->assertForbidden();

        // Nothing was asked of RingCentral or Workiz on the way to either.
        Http::assertNothingSent();
    }

    public function test_the_builder_pane_needs_its_own_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', [
            'reports.view',
            'reports.build',
            'reports.library.view',
        ]);

        // The field tree is the builder's pane.
        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/fields')
            ->assertForbidden();

        // ...and so is saving what it builds. A hidden pane whose endpoint
        // still answers is a decoration, and the URL is one paste away from
        // anybody who has ever seen it.
        $this->signedInAs($runner)
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Nope',
                'definition' => ['resource' => 'jobs', 'columns' => ['jobs.title']],
            ])
            ->assertForbidden();
    }

    public function test_the_library_needs_its_own_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($tenant, 'Analyst', [
            'reports.view',
            'reports.build',
            'reports.builder.view',
        ]);

        $this->signedInAs($builder)
            ->getJson('/api/v1/reports')
            ->assertForbidden();
    }

    /**
     * THE TWO TABS ARE INDEPENDENT, which is the point of splitting them.
     *
     * Asserted both ways round rather than once: the pair of tests above each
     * withhold one tab, and a gate that refused everything would pass both. The
     * case that distinguishes is somebody holding one tab reaching THAT one
     * while still refused the other, which is what the two positive assertions
     * here add.
     */
    public function test_holding_one_tab_opens_that_one_and_not_its_neighbour(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $librarian = $this->userWithRole($tenant, 'Librarian', [
            'reports.view',
            'reports.library.view',
        ]);

        $this->signedInAs($librarian)
            ->getJson('/api/v1/reports')
            ->assertOk();

        $this->signedInAs($librarian)
            ->getJson('/api/v1/reports/fields')
            ->assertForbidden();
    }

    /**
     * A TAB GRANT NARROWS. IT NEVER WIDENS.
     *
     * Settings read the new gate as letting somebody with the tab and without
     * `reports.build` into the Experimental pane, which would put an affordance
     * and its gate in disagreement: they could read the report and not connect
     * the Workiz account it reads from.
     *
     * Changed 2026-10-15. Jose: people assigned to one company "should be
     * able to see it regardless". Reading the pane needs the tab alone; the
     * two writes (tag, convert) need their own tick, reports.handles (2026-11-02).
     * Without the tab, nothing opens.
     */
    /** Jose, 2026-11-02: a Dispatch Supervisor was refused Handles with no tick in Roles to grant it. */
    public function test_the_handles_tick_allows_tag_and_convert_without_reports_build(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        config(['experimental_reports.ringcentral.client_id' => null, 'experimental_reports.ringcentral.client_secret' => null, 'experimental_reports.ringcentral.jwt' => null]);

        $supervisor = $this->userWithRole($tenant, 'Dispatch Supervisor', ['reports.view', 'reports.experimental.view', 'reports.handles']);
        $this->signedInAs($supervisor)->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'X', 'tag' => 'new'])->assertOk();
        $this->signedInAs($supervisor)->postJson('/api/v1/reports/experimental/calls/1/convert')->assertOk();
    }

    public function test_the_tab_alone_opens_the_pane_but_not_its_writes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        config(['experimental_reports.ringcentral.client_id' => null, 'experimental_reports.ringcentral.client_secret' => null, 'experimental_reports.ringcentral.jwt' => null]);

        $watcher = $this->userWithRole($tenant, 'Watcher', ['reports.view', 'reports.experimental.view']);
        $this->signedInAs($watcher)->getJson('/api/v1/reports/experimental/calls')->assertOk();
        $this->signedInAs($watcher)->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'X', 'tag' => 'new'])->assertForbidden();
        $this->signedInAs($watcher)->postJson('/api/v1/reports/experimental/calls/1/convert')->assertForbidden();

        $noTab = $this->userWithRole($tenant, 'Runner', ['reports.view', 'reports.build']);
        $this->signedInAs($noTab)->getJson('/api/v1/reports/experimental/calls')->assertForbidden();
    }

    /**
     * READING PROFIT & LOSS AND CONNECTING ITS BASE ARE ONE GRANT.
     *
     * Reading a base needs `profit_loss.view`. Connecting one needs
     * `reports.build`, because SocialProvider's Airtable arm says so — written
     * before Profit & Loss was a module of its own, when the only thing reading
     * a base was going to be a report.
     *
     * So the two grants come apart in both directions, and this asserts the
     * live behaviour rather than the behaviour I would choose. It is here so
     * that whoever settles it has the measurement instead of a description, and
     * so that changing the permission has to change a test that says why.
     */
    public function test_reading_profit_and_loss_and_connecting_a_base_are_different_grants(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $accountant = $this->userWithRole($tenant, 'Accountant', ['profit_loss.view']);

        // Faked, because past the permission gate this controller VERIFIES the
        // base with Airtable. Without this the test reaches the real API from
        // a permission suite, which is the trap the RingCentral control here
        // already caught once.
        Http::fake(['api.airtable.com/*' => Http::response([], 401)]);

        // Reads the figures.
        $this->signedInAs($accountant)
            ->getJson('/api/v1/reports/profit-loss/sources')
            ->assertOk();

        // ...and may connect the base they read FROM, which is the half that
        // was wrong until today: Connect asked for `reports.build`, so the
        // person who reads the figures every week could not attach a new base
        // and somebody who could never open them was offered the button.
        //
        // Not asserting 200 - there is no Airtable to verify against here, and
        // a stubbed success would be testing Http::fake. What is asserted is
        // that the refusal is no longer the PERMISSION one.
        $this->signedInAs($accountant)
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_whatever',
                'base_id' => 'appWhbwht0xgB5Yut',
            ])
            ->assertStatus(422);
    }

    /**
     * CONNECTING WORKIZ FROM INTEGRATIONS IS NOT THE EXPERIMENTAL TAB.
     *
     * The same controller action is registered twice: once under
     * `reports/experimental/workiz`, which belongs to the pane and is gated
     * with it, and once under `social/workiz` for Settings > Integrations.
     * Settings' copy must keep answering somebody who has never opened Reports
     * — this is the "a route serving two audiences" trap, and the two audiences
     * were given two routes rather than one middleware that cannot describe
     * both.
     */
    public function test_integrations_can_still_connect_workiz_without_the_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        /*
         * NOT the tenant admin, and the first draft of this was.
         * Role::isAdministrator() bypasses the permission table entirely, so an
         * admin passes every gate and this test proved nothing: it failed with
         * a 422 from the controller, several layers past the check it claimed
         * to be about.
         *
         *  is what SocialProvider asks for to connect Workiz, so
         * this is somebody genuinely entitled to connect one and not entitled
         * to the pane.
         */
        $connector = $this->userWithRole($tenant, 'Connector', [
            'reports.view',
            'reports.build',
        ]);

        $this->signedInAs($connector)
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'x'])
            ->assertForbidden();

        // The same person, the same action, the other door — and whatever this
        // answers, it is not the tab refusing.
        $status = $this->signedInAs($connector)
            ->postJson('/api/v1/social/workiz', ['api_token' => 'x'])
            ->getStatusCode();

        $this->assertNotSame(403, $status);
    }
}
