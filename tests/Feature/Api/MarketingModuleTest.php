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

        // Reaching the tab is `view`. Connecting a page is `edit`. A `send`
        // for Newsletter is still not declared — that would grant a capability
        // the software does not have.
        $this->assertSame(['marketing.view', 'marketing.edit'], $module->permissionKeys());

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
        $alpha  = $this->provisionTenant('Beta');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $message = $this->signedInAs($viewer)
            ->getJson(self::NEWSLETTER)
            ->assertForbidden()
            ->json('message');

        // R8: a sentence saying what to do, naming the act and the remedy.
        $this->assertStringContainsString('open Marketing', $message);
        $this->assertStringContainsString('role', $message);

        // And never the key. Somebody reading "marketing.view" learns a string
        // they cannot act on, and the roles screen is not where they are.
        $this->assertStringNotContainsString('marketing.view', $message);
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
        $alpha    = $this->provisionTenant('Gamma');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

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
        $alpha    = $this->provisionTenant('Delta');
        $viewer   = $this->userWithRole($alpha, 'Viewer', ['customers.view']);
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
     * Leads, Reviews and Business Profile are tabs INSIDE Marketing.
     *
     * The owner's call: the sidebar was fifteen rows deep and three of them were
     * the same subject — what the public is told about the business, what they
     * say back, and who rang. They are still three modules, and this is the one
     * fact that changed about them.
     */
    public function test_the_three_public_facing_modules_are_drawn_inside_marketing(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            ['leads', 'reviews', 'business_profile'],
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
     * The failure worth guarding: somebody reads "Leads is inside Marketing" as
     * a permission relationship and lets `marketing.view` carry the three with
     * it. That is a silent grant of three modules' data, and §4 does not let a
     * layout decision make one.
     */
    public function test_being_inside_marketing_grants_nothing(): void
    {
        $alpha    = $this->provisionTenant('Theta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

        $modules = collect(
            $this->signedInAs($marketer)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['marketing']['reachable']);

        foreach (['leads', 'reviews', 'business_profile'] as $key) {
            $this->assertFalse(
                $modules[$key]['reachable'],
                "{$key} came with marketing.view, which is a grant nobody made.",
            );
            $this->assertSame(self::MODULE, $modules[$key]['parent']);
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
        $alpha  = $this->provisionTenant('Iota');
        $caller = $this->userWithRole($alpha, 'Caller', ['leads.view']);

        $modules = collect(
            $this->signedInAs($caller)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['leads']['reachable']);
        $this->assertFalse($modules['marketing']['reachable']);
        $this->assertSame(self::MODULE, $modules['leads']['parent']);
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

        $this->assertSame(self::MODULE, $modules['leads']['parent']);
        $this->assertNull($modules['marketing']['parent']);
    }

    /** The automation builder groups its pickers the same way. */
    public function test_the_automation_catalogue_says_where_each_module_is_drawn(): void
    {
        $alpha  = $this->provisionTenant('Lambda');
        $caller = $this->userWithRole($alpha, 'Caller', [
            'leads.view', 'marketing.view', 'automations.view',
        ]);

        $modules = collect(
            $this->signedInAs($caller)->getJson('/api/v1/automations/catalogue')->assertOk()
                ->json('data.modules')
        )->keyBy('key');

        $this->assertSame(self::MODULE, $modules['leads']['parent']);
        $this->assertNull($modules['marketing']['parent']);
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
        $this->assertNotEmpty($module->automationActions, 'Marketing declares no actions.');

        $this->assertArrayNotHasKey(
            self::MODULE,
            app(ModuleRegistry::class)->violatingAutomationRule(),
        );
    }

    /**
     * The honesty half, and the reason `isRaised` exists.
     *
     * Nothing sends a newsletter, so those events must say so — a trigger listed
     * without that mark is one somebody builds a rule on, switches on and never
     * hears from again. `ModuleRegistryTest` holds the flag to the source in the
     * other direction; this holds it to the truth today.
     *
     * ── Why this is two lists and no longer one ──────────────────
     *
     * It used to assert that EVERY trigger Marketing declares is unraised, which
     * was true while Newsletter was all the module had and stopped being true
     * the day publishing landed. Asserting both sides is the better test anyway:
     * "they are all false" would pass just as happily against a flag that was
     * hardcoded false and could never say otherwise.
     */
    public function test_the_newsletter_events_say_nothing_raises_them_and_the_publishing_ones_do_not(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $waiting = ['newsletter.sent', 'newsletter.unsubscribed'];
        $firing  = ['social.published', 'social.publish_failed'];

        // The control. Every assertion below is inside a loop, so a module that
        // declared nothing at all would pass this while proving nothing.
        $this->assertSame(
            [...$waiting, ...$firing],
            array_map(fn (TriggerDefinition $t) => $t->key, $module->triggers),
        );

        foreach ($module->triggers as $trigger) {
            if (in_array($trigger->key, $waiting, true)) {
                $this->assertFalse(
                    $trigger->isRaised,
                    "'{$trigger->key}' claims something raises it. If that is now true it is "
                    .'very welcome, and the wording has to stop saying otherwise.',
                );

                $this->assertStringContainsString(
                    TriggerDefinition::NOT_RAISED_YET,
                    $trigger->description,
                    "'{$trigger->key}' is flagged unraised but the tenant is not told.",
                );

                continue;
            }

            $this->assertTrue(
                $trigger->isRaised,
                "'{$trigger->key}' is declared unraised, but publishing emits it — a tenant "
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
            $alpha, 'Marketer', ['automations.view', 'marketing.view'],
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
        $planner = $this->userWithRole($alpha, 'Planner', ['automations.view']);

        $withheld = $this->signedInAs($planner)
            ->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.modules');

        $this->assertNull(collect($withheld)->firstWhere('key', self::MODULE));
    }

    // ── the endpoint keeps no dictionary of its own ──────────────

    public function test_the_endpoint_publishes_what_the_module_declared_and_invents_nothing(): void
    {
        $alpha    = $this->provisionTenant('Zeta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

        $body = $this->signedInAs($marketer)
            ->getJson(self::NEWSLETTER)->assertOk()->json('data');

        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertSame(['key' => self::MODULE, 'label' => 'Marketing'], $body['module']);

        $this->assertSame(
            array_map(fn (TriggerDefinition $t) => [
                'key'         => $t->key,
                'label'       => $t->label,
                'description' => $t->description,
                'raised'      => $t->isRaised,
            ], $module->triggers),
            $body['triggers'],
            'The endpoint and the registry disagree about what Marketing raises.',
        );

        $this->assertSame(
            array_map(fn (ActionDefinition $a) => [
                'key'         => $a->key,
                'label'       => $a->label,
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
        $alpha    = $this->provisionTenant('Eta');
        $marketer = $this->userWithRole($alpha, 'Marketer', ['marketing.view']);

        $triggers = $this->signedInAs($marketer)
            ->getJson(self::NEWSLETTER)->assertOk()->json('data.triggers');

        $this->assertNotEmpty($triggers);

        // Both values, keyed by trigger. A payload that answered false for
        // everything would satisfy a loop of assertFalse and tell the screen
        // nothing — the flag is only worth publishing if it can say either.
        $this->assertSame([
            'newsletter.sent'        => false,
            'newsletter.unsubscribed' => false,
            'social.published'       => true,
            'social.publish_failed'  => true,
        ], collect($triggers)->pluck('raised', 'key')->all());
    }
}
