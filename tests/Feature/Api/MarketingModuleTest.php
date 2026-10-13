<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Marketing — a module declared before it is built (§5.5, §4).
 *
 * ── What is worth testing about something that does nothing ──────
 *
 * Three things, and none of them is "does the endpoint return 200".
 *
 * The first is that being new exempts it from nothing. A placeholder is exactly
 * the kind of module somebody would wave through the permission model on the
 * grounds that there is nothing behind it to protect — and the tab would then be
 * the one module in the app an organization cannot withhold.
 *
 * The second is the §5.5 standing rule, which is the reason this module is
 * declared now rather than when it works. Marketing has to say what Newsletter
 * will raise AND that nothing raises it yet, or somebody builds a rule that
 * saves, switches on and waits forever.
 *
 * The third is that the screen and the registry do not each keep their own copy
 * of that answer. The endpoint publishes what the module declared, word for
 * word, or there are two dictionaries and one of them will go stale.
 */
class MarketingModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const MODULE = 'marketing';

    private const NEWSLETTER = '/api/v1/marketing/newsletter';

    // ── registered, and grantable like anything else (§4) ────────

    public function test_the_module_is_registered_and_offers_exactly_the_tab_permission(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module, 'Marketing is not in the registry, so it has no tab at all.');
        $this->assertSame('Marketing', $module->label);

        // Reaching the tab is `view`. Connecting a page is `edit`. Since
        // 2026-09-29 the panes are grantable one at a time as well (Jose: tabs
        // grantable "like all the others"), in the order the strip draws;
        // Posts became Dashboard, Compose and Calendar on 2026-10-12.
        $this->assertSame(
            ['marketing.view', 'marketing.edit', 'marketing.dashboard.view', 'marketing.compose.view', 'marketing.calendar.view', 'marketing.accounts.view', 'marketing.qr_codes.view'],
            $module->permissionKeys(),
        );

        $this->assertContains(
            'marketing.view',
            app(ModuleRegistry::class)->allPermissionKeys(),
            'The permission the tab is gated on is not one the sync command would ever create.',
        );
        $this->assertContains(
            'marketing.edit',
            app(ModuleRegistry::class)->allPermissionKeys(),
            'Connecting a page is not a permission the sync command would ever create.',
        );
    }

    /**
     * Declared is not the same as grantable, and the roles editor is where the
     * difference shows. A module missing from that tree can be gated on a
     * permission no administrator can find, which is a tab nobody can ever open.
     */
    public function test_an_organization_can_grant_the_tab_from_the_roles_editor(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $marketing = collect($modules)->firstWhere('key', self::MODULE);

        $this->assertNotNull($marketing, 'Marketing is not offered in the roles editor.');
        $this->assertSame('Marketing', $marketing['label']);
        $this->assertContains(
            'marketing.view',
            collect($marketing['actions'])->pluck('key')->all(),
        );
        $this->assertContains(
            'marketing.edit',
            collect($marketing['actions'])->pluck('key')->all(),
        );
    }

    // ── the gate, which being new does not lift ──────────────────

    public function test_the_tab_is_refused_to_a_role_the_organization_did_not_grant_it(): void
    {
        $alpha = $this->provisionTenant('Beta');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $message = $this->signedInAs($viewer)
            ->getJson(self::NEWSLETTER)
            ->assertForbidden()
            ->json('message');

        // R8: a sentence saying what to do, naming the act and the remedy.
        // The act is opening NEWSLETTER since 2026-09-04 — the endpoint moved
        // behind that module's own gate when `send` needed to be grantable
        // apart from `edit`.
        $this->assertStringContainsString('open Newsletter', $message);
        $this->assertStringContainsString('role', $message);

        // And never the key. Somebody reading "newsletter.view" learns a string
        // they cannot act on, and the roles screen is not where they are.
        $this->assertStringNotContainsString('newsletter.view', $message);
    }

    /**
     * The control for the test above.
     *
     * Without it, a refusal that fired for the wrong reason — a mistyped route,
     * a missing controller, a middleware refusing everybody — reads as the
     * permission model working perfectly.
     */
    public function test_a_role_the_organization_granted_it_reaches_the_tab(): void
    {
        $alpha = $this->provisionTenant('Gamma');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['newsletter.view']);

        $this->signedInAs($marketer)->getJson(self::NEWSLETTER)->assertOk();
    }

    /**
     * The sidebar's half of the same rule.
     *
     * The tab is drawn from `/me`'s `modules` block, so a module that came back
     * reachable for somebody without the grant would put a destination in front
     * of them that answers 403 the moment they tap it.
     */
    public function test_the_tab_is_reachable_in_me_only_for_a_role_that_holds_it(): void
    {
        $alpha = $this->provisionTenant('Delta');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

        $this->assertFalse($this->marketingInMeFor($viewer)['reachable']);
        $this->assertTrue($this->marketingInMeFor($marketer)['reachable']);
    }

    /** Marketing's entry in a user's `/me` modules block. */
    private function marketingInMeFor(User $user): array
    {
        $modules = $this->signedInAs($user)
            ->getJson('/api/v1/me')->assertOk()->json('data.modules');

        $marketing = collect($modules)->firstWhere('key', self::MODULE);

        // Present for everybody, reachable for some: the sidebar needs to tell
        // "your plan does not include this" from "your role does not reach it".
        $this->assertNotNull($marketing, 'Marketing is missing from /me entirely.');

        return $marketing;
    }

    // ── the three modules drawn inside it ────────────────────────

    /**
     * Newsletter and Web Management are the tabs INSIDE Marketing.
     *
     * The owner's call: the sidebar was fifteen rows deep and several of them
     * were the same subject — what the public is told about the business, and
     * what they say back.
     *
     * THE LIST CHANGED ONCE AND NEARLY CHANGED TWICE. Leads left for a row of
     * its own on 2026-09-03 once Thumbtack became a second source, and Web
     * Management took its place.
     *
     * On 2026-09-23 Reviews and Business Profile were moved into a new A1Leads
     * GBP tab and PUT BACK THE SAME DAY. That tab is a read-only window onto
     * Rodrigo's A1 Leads and is temporary; these two are EzBizi's own modules,
     * reading EzBizi's own tables, and they are what a Google reviewer is shown
     * when our own Business Profile API access arrives. Moving them was the
     * mistake and this assertion is what noticed - which is the argument for it
     * naming every child rather than being loosened to match whatever is there.
     *
     * On 2026-09-25 Jose moved Reviews and Business Profile into a new
     * Customer Experience tab (EzBizi's own, not A1Leads GBP), leaving two.
     */
    public function test_the_public_facing_modules_are_drawn_inside_marketing(): void
    {
        $registry = app(ModuleRegistry::class);

        // Newsletter joined on 2026-09-04. It was a chip with no permissions of
        // its own until `send` had to be grantable separately from `edit` —
        // drafting a mailing and putting it in front of twelve hundred
        // customers are different acts, and only a module can express that.
        $this->assertSame(
            ['newsletter', 'web_management'],
            array_map(fn ($m) => $m->key, $registry->childrenOf(self::MODULE)),
            'Registry order is the tab order, so this is the order of the strip.',
        );

        $this->assertNull(
            $registry->get(self::MODULE)?->parent,
            'Marketing is top level — nesting is one deep.',
        );
    }

    /**
     * Nested is not granted.
     *
     * The failure worth guarding: somebody reads "Newsletter is inside
     * Marketing" as a permission relationship and lets `marketing.view` carry
     * the children with it. That is a silent grant of another module's data,
     * and §4 does not let a layout decision make one.
     *
     * ── NEWSLETTER IS IN THIS LOOP BECAUSE IT IS THE ONE THAT SHIPPED ──
     *
     * The list shrank from three to one as modules left for containers of
     * their own, and `newsletter` was never added when it arrived — so the
     * loop covered the children that had gone and not the one that stayed.
     *
     * That is not hypothetical here. The Newsletter CHIP was drawn on
     * `reaches('marketing')` for weeks: a role with `marketing.view` alone was
     * offered a tab whose every request the server then refused, and a role
     * with `newsletter.view` alone got no tab at all (`2c46edab`). The server
     * side was correct throughout, which is why this test stayed green — but
     * a loop naming every child is the thing that would have made the gap
     * visible rather than leaving it to a screenshot.
     *
     * Keep this list derived from the registry's children in spirit: when a
     * module joins Marketing, it joins here.
     */
    public function test_being_inside_marketing_grants_nothing(): void
    {
        $alpha = $this->provisionTenant('Theta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

        $modules = collect(
            $this->signedInAs($marketer)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['marketing']['reachable']);

        foreach (['newsletter', 'web_management'] as $key) {
            $this->assertFalse(
                $modules[$key]['reachable'],
                "{$key} came with marketing.view, which is a grant nobody made.",
            );
            $this->assertSame(self::MODULE, $modules[$key]['parent']);
        }

        /*
         * The positive control, and the hazard it covers is NARROWER than the
         * one I first wrote here.
         *
         * I claimed a payload that stopped reporting these modules would
         * satisfy the loop above. **It would not**, and EZ Leads measured it
         * before I did: `keyBy` plus `$modules['key'][...]` turns a dropped
         * module into "Undefined array key", which PHPUnit raises as an ERROR.
         * I checked it myself with a key that does not exist — errors: 1, not
         * a pass. The access shape already covers the missing-payload case.
         *
         * What it does NOT cover, and what this control is for: every
         * assertion above is false, so it passes for a `/me` that reports
         * these modules unreachable for EVERYBODY — a broken reach
         * calculation, a role lookup that always answers no. Asking the same
         * two keys as somebody who holds them is what separates "correctly
         * withheld" from "withheld from everyone".
         *
         * Two hazards that look like one from a distance. The access shape
         * catches the absent module; this catches the always-false answer.
         */
        $both = $this->userWithRole($alpha, 'Both', [
            'marketing.view', 'newsletter.view', 'web_management.view',
        ], 'both@theta.test');

        $granted = collect(
            $this->signedInAs($both)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        foreach (['newsletter', 'web_management'] as $key) {
            $this->assertTrue(
                $granted[$key]['reachable'],
                "{$key} is not reachable even when its own view is granted, so "
                .'the loop above proves nothing about the grant.',
            );
        }
    }

    /**
     * And the reverse: holding a child's view without the parent's.
     *
     * This is the case that decides whether the move can take a module AWAY
     * from a role that holds it. The client draws Marketing for the sake of the
     * one tab this person may open, so `parent` has to travel even when the
     * parent itself is out of reach.
     */
    public function test_a_child_is_reachable_without_the_parent(): void
    {
        $alpha = $this->provisionTenant('Iota');
        $caller = $this->userWithRole($alpha, 'Caller', ['web_management.view']);

        $modules = collect(
            $this->signedInAs($caller)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['web_management']['reachable']);
        $this->assertFalse($modules['marketing']['reachable']);
        $this->assertSame(self::MODULE, $modules['web_management']['parent']);
    }

    /** The roles editor is built from this, and nests on the same field. */
    public function test_the_grantable_tree_says_where_each_module_is_drawn(): void
    {
        $alpha = $this->provisionTenant('Kappa');
        $admin = $alpha['admin'];

        $modules = collect(
            $this->signedInAs($admin)->getJson('/api/v1/roles/catalogue')->assertOk()
                ->json('modules')
        )->keyBy('key');

        $this->assertSame(self::MODULE, $modules['web_management']['parent']);
        $this->assertNull($modules['marketing']['parent']);
    }

    /** The automation builder groups its pickers the same way. */
    public function test_the_automation_catalogue_says_where_each_module_is_drawn(): void
    {
        $alpha = $this->provisionTenant('Lambda');
        $caller = $this->userWithRole($alpha, 'Caller', [
            'reviews.view', 'marketing.view', 'automations.view',
            // `automations/catalogue` is behind the Build tab since 891f8378.
            'automations.build.view',
        ]);

        $modules = collect(
            $this->signedInAs($caller)->getJson('/api/v1/automations/catalogue')->assertOk()
                ->json('data.modules')
        )->keyBy('key');

        /*
         * Reviews still answers this; since 2026-09-25 it is drawn in
         * Customer Experience, not here. The question the test asks
         * is whether the catalogue reports the nesting AT ALL, so the right
         * repair is the new parent rather than a different module.
         *
         * Web Management would have been the tidier-looking substitute and is
         * wrong: the catalogue only carries modules the caller can reach, and
         * this caller holds `reviews.view`. It failed with "Undefined array key
         * web_management", which is the catalogue working correctly.
         */
        $this->assertSame('customer_experience', $modules['reviews']['parent']);
        $this->assertNull($modules['marketing']['parent']);
    }

    /**
     * Leads left Marketing and took nothing with it.
     *
     * This is the guard on the move itself. `parent` is documented as changing
     * only where a module is DRAWN, and the whole risk of a reshuffle is that
     * somebody "tidies up" the permission alongside the layout — renaming
     * `leads.view`, folding it into `marketing.view`, or moving the `lead.*`
     * triggers under a different subject. Any of those silently takes Leads
     * away from every role that holds it, or hands it to every role that does
     * not, and the sidebar would look correct either way.
     */
    public function test_leads_moved_out_without_taking_its_permissions_with_it(): void
    {
        $registry = app(ModuleRegistry::class);
        $leads = $registry->get('leads');

        $this->assertNotNull($leads);
        $this->assertNull($leads->parent, 'Leads is a row of its own now.');
        $this->assertTrue($leads->sidebar);

        // The permission keys are unchanged, so no role gained or lost anything.
        $alpha = $this->provisionTenant('Mu');
        $caller = $this->userWithRole($alpha, 'Caller', ['leads.view']);

        $modules = collect(
            $this->signedInAs($caller)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['leads']['reachable'], 'leads.view still reaches Leads.');
        $this->assertNull($modules['leads']['parent'], 'and it is drawn on its own.');

        // Marketing did NOT come along for the ride. Before the move somebody
        // holding leads.view was shown Marketing as a container; now there is
        // nothing to contain it, and drawing Marketing anyway would show a tab
        // whose every child is out of reach.
        $this->assertFalse($modules['marketing']['reachable']);

        // The events a rule is built on are keyed on the module, not its place.
        $this->assertNotEmpty($leads->triggers, 'Leads lost its triggers in the move.');
        $this->assertContains(
            'lead.received',
            array_map(fn ($t) => $t->key, $leads->triggers),
        );
    }

    // ── the §5.5 standing rule, at the moment the module is declared ──

    public function test_the_module_declares_an_automation_surface_rather_than_an_exemption(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNull(
            $module->automationExemptBecause,
            'Marketing claimed an exemption. A newsletter having been sent is an event a '
            .'business builds a rule on, so there is nothing here to be exempt from.',
        );

        $this->assertNotEmpty($module->triggers, 'Marketing declares no events.');

        /*
         * No assertion that Marketing declares ACTIONS, and that is the change
         * of 2026-09-04 rather than a gap. Its only actions were the newsletter
         * pair, and they moved to the `newsletter` module beside the events they
         * act on. §5.5 asks a module for an automation surface or an honest
         * exemption; triggers alone are a surface, and Marketing's are the two
         * social events that fire on every publish.
         */
        $newsletter = app(ModuleRegistry::class)->get('newsletter');
        $this->assertNotEmpty(
            $newsletter->automationActions,
            'The newsletter actions did not arrive with the module they moved to.',
        );

        $this->assertArrayNotHasKey(
            self::MODULE,
            app(ModuleRegistry::class)->violatingAutomationRule(),
        );
    }

    /**
     * Marketing's own events, and every one of them fires.
     *
     * ── What this test used to be, and why it changed twice ─────
     *
     * It began as "every trigger Marketing declares is unraised", which was true
     * while Newsletter was all the module had. Publishing landed and it became
     * two named lists, one waiting and one firing — because "they are all false"
     * passes just as happily against a flag hardcoded false as against a correct
     * one.
     *
     * On 2026-09-04 the waiting half emptied: the newsletter events moved to the
     * `newsletter` module and BOTH now fire, so Marketing is left with the
     * social pair. The two-list shape is kept anyway — a module that grows a new
     * unraised event should land in `$waiting` rather than quietly weakening
     * this into a loop of assertTrue.
     */
    public function test_marketings_events_are_the_publishing_pair_and_both_fire(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $waiting = [];
        $firing = ['social.published', 'social.publish_failed'];

        $this->assertSame(
            [...$waiting, ...$firing],
            array_map(fn (TriggerDefinition $t) => $t->key, $module->triggers),
        );

        foreach ($module->triggers as $trigger) {
            if (in_array($trigger->key, $waiting, true)) {
                $this->assertFalse($trigger->isRaised, "'{$trigger->key}' claims something raises it.");
                $this->assertStringContainsString(
                    TriggerDefinition::NOT_RAISED_YET,
                    $trigger->description,
                    "'{$trigger->key}' is flagged unraised but the tenant is not told.",
                );

                continue;
            }

            $this->assertTrue(
                $trigger->isRaised,
                "'{$trigger->key}' is declared unraised, but something emits it — a tenant "
                .'would be told a working rule cannot run.',
            );

            $this->assertStringNotContainsString(
                TriggerDefinition::NOT_RAISED_YET,
                $trigger->description,
                "'{$trigger->key}' fires, and the description still warns that it does not.",
            );
        }
    }

    /**
     * The newsletter events went WITH the module, and started firing.
     *
     * They were declared unraised on Marketing from the day Marketing existed.
     * Moving them without turning them on would have been the same lie in a new
     * place; turning them on without moving them would have left a rule about
     * mailings filed under the wrong subject in the builder.
     */
    public function test_the_newsletter_events_moved_to_their_module_and_now_fire(): void
    {
        $newsletter = app(ModuleRegistry::class)->get('newsletter');

        $this->assertSame(
            ['newsletter.sent', 'newsletter.unsubscribed'],
            array_map(fn (TriggerDefinition $t) => $t->key, $newsletter->triggers),
        );

        foreach ($newsletter->triggers as $trigger) {
            $this->assertTrue($trigger->isRaised, "'{$trigger->key}' is declared unraised.");
            $this->assertStringNotContainsString(
                TriggerDefinition::NOT_RAISED_YET,
                $trigger->description,
                "'{$trigger->key}' fires, and still warns that it does not.",
            );
        }

        // `send` is the reason this is a module rather than two more marketing
        // keys: drafting and mailing twelve hundred people are different acts.
        // And its three panes, grantable one at a time since 2026-09-29.
        $this->assertSame(
            ['view', 'create', 'edit', 'delete', 'send', 'dashboard.view', 'composer.view', 'settings.view'],
            $newsletter->actions,
        );
        $this->assertSame('marketing', $newsletter->parent);
    }

    /**
     * The events reach the builder, gated the same way the tab is.
     *
     * A surface declared in the registry and absent from `/automations/catalogue`
     * would satisfy every assertion above and still be invisible to the only
     * screen where anybody would use it.
     */
    public function test_the_declared_events_reach_the_automation_builder_and_only_with_the_tab(): void
    {
        $alpha = $this->provisionTenant('Epsilon');

        $marketer = $this->userWithRole(
            $alpha, 'Marketer',
            // `automations.build.view`: the catalogue moved behind the Build tab
            // in 891f8378, and this test is about what the catalogue CONTAINS.
            ['automations.view', 'automations.build.view', 'marketing.view'],
        );

        $modules = $this->signedInAs($marketer)
            ->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.modules');

        $marketing = collect($modules)->firstWhere('key', self::MODULE);

        $this->assertNotNull($marketing, 'Marketing is not offered to the rule builder.');
        $this->assertNotEmpty($marketing['triggers']);

        foreach ($marketing['triggers'] as $trigger) {
            // Only the ones nothing raises carry the warning. The publishing
            // pair reaches the builder as ordinary working triggers, which is
            // the difference the sentence exists to draw.
            if (str_starts_with($trigger['key'], 'newsletter.')) {
                $this->assertStringContainsString(
                    TriggerDefinition::NOT_RAISED_YET,
                    $trigger['description'],
                    'The builder would offer this without saying a rule on it cannot run.',
                );

                continue;
            }

            $this->assertStringNotContainsString(
                TriggerDefinition::NOT_RAISED_YET,
                $trigger['description'],
                $trigger['key'].' fires, and the builder still warns that it does not.',
            );
        }

        // The other half: the wording modules write for themselves is real
        // information about the business, so a role without the tab does not
        // get to read Marketing's.
        // Holds the Build tab, and deliberately not Marketing: what is being
        // withheld here is one module's wording, not the whole catalogue.
        $planner = $this->userWithRole(
            $alpha, 'Planner', ['automations.view', 'automations.build.view'],
        );

        $withheld = $this->signedInAs($planner)
            ->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.modules');

        $this->assertNull(collect($withheld)->firstWhere('key', self::MODULE));
    }

    // ── the endpoint keeps no dictionary of its own ──────────────

    public function test_the_endpoint_publishes_what_the_module_declared_and_invents_nothing(): void
    {
        $alpha = $this->provisionTenant('Zeta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['newsletter.view']);

        $body = $this->signedInAs($marketer)
            ->getJson(self::NEWSLETTER)->assertOk()->json('data');

        // The endpoint moved from `marketing` to `newsletter` on 2026-09-04,
        // with the events it publishes. Reading `marketing` here would assert
        // against the social pair.
        $module = app(ModuleRegistry::class)->get('newsletter');

        $this->assertSame(['key' => 'newsletter', 'label' => 'Newsletter'], $body['module']);

        $this->assertSame(
            array_map(fn (TriggerDefinition $t) => [
                'key' => $t->key,
                'label' => $t->label,
                'description' => $t->description,
                'raised' => $t->isRaised,
            ], $module->triggers),
            $body['triggers'],
            'The endpoint and the registry disagree about what Newsletter raises.',
        );

        $this->assertSame(
            array_map(fn (ActionDefinition $a) => [
                'key' => $a->key,
                'label' => $a->label,
                'description' => $a->description,
            ], $module->automationActions),
            $body['actions'],
        );

        // The control against a payload that is right by being empty.
        $this->assertNotEmpty($body['triggers']);
        $this->assertNotEmpty($body['actions']);
    }

    /**
     * The flag the screen draws its mark from, in the payload rather than only
     * in the prose.
     *
     * A client picking "does this fire" out of an English sentence is a client
     * that breaks the day somebody rewords the sentence.
     */
    public function test_the_payload_flags_each_event_that_nothing_raises(): void
    {
        $alpha = $this->provisionTenant('Eta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['newsletter.view']);

        $triggers = $this->signedInAs($marketer)
            ->getJson(self::NEWSLETTER)->assertOk()->json('data.triggers');

        $this->assertNotEmpty($triggers);

        // Both values, keyed by trigger. A payload that answered false for
        // everything would satisfy a loop of assertFalse and tell the screen
        // nothing — the flag is only worth publishing if it can say either.
        // Both fire now. The flag is still worth publishing, and this still
        // asserts the VALUE rather than looping assertTrue, because a payload
        // that answered true for everything would satisfy a loop and tell the
        // screen nothing.
        $this->assertSame([
            'newsletter.sent' => true,
            'newsletter.unsubscribed' => true,
        ], collect($triggers)->pluck('raised', 'key')->all());
    }
}
