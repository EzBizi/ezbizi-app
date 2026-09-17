<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A tab a role may not open does not answer either (Jose, 2026-09-17).
 *
 * His instruction was about Human Resources — *"i want them to see their work
 * hours, but not the onboarding information"* — and then *"do it for all
 * modules"*. Web Management's two tabs are SEO, what the website SAYS, and
 * Widgets, what a visitor can START on it. Every conversation begun in the
 * second becomes a lead, so "whoever writes the site's copy may not
 * reconfigure the thing that captures enquiries" is the grant this adds.
 *
 * ── What this suite exists to catch ──────────────────────────────
 *
 * Not whether the tab is drawn. A hidden tab whose endpoints still answer is a
 * decoration, not a permission: the URL is one paste away from anybody who has
 * ever seen the pane, and the screen looks right from both sides while it
 * happens. So everything here is about the SERVER. The tab bar's own filtering
 * is asserted in web_screen_test.dart.
 *
 * ── The one endpoint deliberately NOT gated ──────────────────────
 *
 * `GET web/sites` feeds the rail in BOTH tabs (seo_tab.dart:143 and
 * widgets_tab.dart:59). A gate naming either tab would empty the other one's
 * rail, so it stays on `web_management.view` and the last test here pins that
 * as a decision rather than leaving it to look like the gap somebody should
 * close next.
 *
 * ── Why every OTHER web fixture now carries both tab keys ────────
 *
 * Not tidiness. Email found the trap on their own module: when a route moves
 * behind a tab, the refusal tests around it keep passing and their 403 quietly
 * stops meaning what their docblock says. Four of their "a reader may not
 * connect a mailbox by password" tests were green on a 403 that had migrated
 * from `email.edit` to `email.settings.view` — still red-proof, no longer about
 * the authority they claim to measure, and nothing in a test run can show it.
 *
 * So every `Web Viewer` fixture in this directory holds BOTH tabs. Their
 * refusals are then necessarily about the thing each one names — the missing
 * `web_management.edit` — because the only other gate on the route is one the
 * role satisfies.
 *
 * `WebTenantIsolationTest` needs no such care and it is worth saying why: it
 * asserts 404, and a tab refusal is 403. The assertion discriminates on its own,
 * so no permission change can make it pass for the wrong reason. That is the
 * shape to prefer wherever a refusal has more than one possible cause.
 */
class WebTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A role holding one tab, and a site it can act on.
     *
     * `edit` as well as the tab, because the interesting refusals are on writes
     * — rotating a credential, saving a widget config — and a role without
     * `edit` would be refused for a reason that has nothing to do with tabs.
     * That is the trap the brief names: a suite of 403s where the 403s are
     * about something else reads exactly like the feature working.
     *
     * @param  array<int, string>  $tabs
     */
    private function roleHolding(array $tabs): array
    {
        $tenant = $this->provisionTenant('Alpha');

        $user = $this->userWithRole($tenant, 'Web Person', [
            'web_management.view',
            'web_management.edit',
            ...$tabs,
        ]);

        /*
         * Registered over HTTP by the same person, which is itself a small
         * assertion: `POST web/sites` is on the ungated apiResource, so a role
         * holding neither tab can still add a host. If that ever stops being
         * true this helper fails on assertCreated and every test here goes red
         * at once, which is the right way to find out.
         */
        $site = $this->signedInAs($user)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');

        return [$user, $site];
    }

    /**
     * THE POSITIVE CONTROL, and the suite is worthless without it.
     *
     * Everything else here asserts 403, and a 403 is also what a mistyped
     * route, an unmaterialised permission key or a fixture with no reach
     * produces. This says the gated endpoints answer the person who holds the
     * tab at all.
     */
    public function test_a_role_holding_only_seo_can_read_the_values(): void
    {
        [$user, $site] = $this->roleHolding(['web_management.seo.view']);

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/values")
            ->assertOk();
    }

    public function test_a_role_holding_only_widgets_can_read_the_widget_config(): void
    {
        [$user, $site] = $this->roleHolding(['web_management.widgets.view']);

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/widgets")
            ->assertOk();
    }

    /**
     * The grant that did not exist yesterday.
     *
     * Before today the smallest thing anybody could give the person who writes
     * the site's copy was `web_management.view`, which includes the chat widget
     * that captures every enquiry the business receives.
     */
    public function test_seo_alone_cannot_reach_the_widgets_tab(): void
    {
        [$user, $site] = $this->roleHolding(['web_management.seo.view']);

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/widgets")
            ->assertForbidden();

        // The write too: hiding the tab while its PUT still answers is the
        // decoration this suite exists to refuse.
        $this->signedInAs($user)
            ->putJson("/api/v1/web/sites/{$site}/widgets", ['config' => []])
            ->assertForbidden();

        $this->signedInAs($user)
            ->deleteJson("/api/v1/web/sites/{$site}/widgets")
            ->assertForbidden();
    }

    public function test_widgets_alone_cannot_reach_the_seo_tab(): void
    {
        [$user, $site] = $this->roleHolding(['web_management.widgets.view']);

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/values")
            ->assertForbidden();

        $this->signedInAs($user)
            ->putJson("/api/v1/web/sites/{$site}/values", ['values' => []])
            ->assertForbidden();

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/content")
            ->assertForbidden();
    }

    /**
     * The credentials, and this is the sharpest one.
     *
     * Settings asked whether "may look at the site" and "may reissue the
     * credential the plugin authenticates with" should be separate grants. They
     * already were — the buttons are behind `web_management.edit` — but the
     * rotation endpoints live in the SEO pane, so somebody granted only Widgets
     * must not reach them even holding `edit`. That is the case where the tab
     * key is doing work the existing split does not.
     */
    public function test_widgets_alone_cannot_rotate_either_credential(): void
    {
        [$user, $site] = $this->roleHolding(['web_management.widgets.view']);

        $this->signedInAs($user)
            ->postJson("/api/v1/web/sites/{$site}/token")
            ->assertForbidden();

        $this->signedInAs($user)
            ->postJson("/api/v1/web/sites/{$site}/public-key")
            ->assertForbidden();

        $this->signedInAs($user)
            ->postJson("/api/v1/web/sites/{$site}/primary")
            ->assertForbidden();
    }

    /**
     * Holding the module and NEITHER tab reaches nothing behind them.
     *
     * The state is reachable: `web_management.view` is what draws the sidebar
     * row, and an administrator can narrow both tabs away. The screen answers
     * that with a sentence naming the missing grant; the server answers it with
     * a refusal, and this pins the second.
     */
    public function test_the_module_alone_opens_neither_tab(): void
    {
        [$user, $site] = $this->roleHolding([]);

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/values")
            ->assertForbidden();

        $this->signedInAs($user)
            ->getJson("/api/v1/web/sites/{$site}/widgets")
            ->assertForbidden();
    }

    /**
     * The rail is NOT gated, and that is a decision.
     *
     * Both tabs list the company's hosts from this one endpoint, so a gate
     * naming either would empty the other's rail — the pane would draw "no
     * websites yet" at somebody whose company has four. Asserted rather than
     * merely commented, so that a later sweep "finishing the job" has to delete
     * a passing test and read why it is here.
     */
    public function test_the_site_list_answers_a_role_holding_neither_tab(): void
    {
        [$user] = $this->roleHolding([]);

        $this->signedInAs($user)
            ->getJson('/api/v1/web/sites')
            ->assertOk();
    }
}
