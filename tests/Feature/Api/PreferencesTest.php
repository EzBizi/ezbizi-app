<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User preferences — §2.1 category 6.
 *
 * The category exists so it is never merged with the others. What these defend
 * is exactly that: preferences are the person's, not the organization's, and one
 * person's cannot be read or written by another.
 */
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function as(string $email = 'a@alpha.test'): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_defaults_arrive_complete_so_the_client_never_guesses(): void
    {
        $response = $this->as()->getJson('/api/v1/me/preferences')->assertOk();

        $data = $response->json('data');

        // Read as an array, not by json path: the KEYS contain dots, so dot
        // notation would traverse into a 'navigation' object that does not exist.
        //
        // The list is the default, deliberately — the owner's preference, and the
        // one most people expect from a CRM sidebar.
        $this->assertSame('list', $data['navigation.style']);
        $this->assertSame('system', $data['appearance.theme']);

        // The catalogue travels with the values, so a preference added next year
        // can be rendered by a client that was never told about it.
        $this->assertNotEmpty($response->json('meta.definitions'));
    }

    public function test_a_preference_survives_and_comes_back_on_me(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Carried on /me as well as its own endpoint, so the app paints the
        // right theme on the first frame rather than flashing the wrong one.
        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame('dark', $me['preferences']['appearance.theme']);
    }

    /**
     * The dial pad's customer panel remembers whether it was pinned.
     *
     * Jose pinned it, left Communication, came back, and found the panel gone
     * and the pin with it — a "keep this open" that meant "until you look at
     * something else". The screen's State goes when the tab does, so the
     * answer cannot live there.
     *
     * Asserted on `/me` rather than only on the preferences endpoint, because
     * `/me` is what the dial pad actually reads on the first frame. A value
     * stored somewhere the screen never looks is the same defect wearing a
     * passing test.
     */
    public function test_the_customer_panel_pin_survives_leaving_the_screen(): void
    {
        $before = $this->as()
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->json('data');

        $this->assertFalse(
            $before['phone.customer_panel_pinned'],
            'the panel is pinned for everybody who has never asked for it',
        );

        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['phone.customer_panel_pinned' => true],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertTrue($me['preferences']['phone.customer_panel_pinned']);
    }

    public function test_a_partial_update_leaves_the_others_alone(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $data = $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.style' => 'blocks'],
        ])->assertOk()->json('data');

        // Two screens changing different preferences must not clobber each other.
        $this->assertSame('dark', $data['appearance.theme']);
        $this->assertSame('blocks', $data['navigation.style']);
    }

    /** UI queue 100: Social's List | Board is remembered per person. */
    public function test_the_social_posts_view_is_remembered(): void
    {
        $data = $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['marketing.social.posts_view' => 'board'],
        ])->assertOk()->json('data');

        $this->assertSame('board', $data['marketing.social.posts_view']);
    }

    /** Jose 2026-10-26: Compose's listings stay expanded or collapsed per person. */
    public function test_the_compose_listings_expansion_is_remembered(): void
    {
        $data = $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['marketing.social.listings_expanded' => true],
        ])->assertOk()->json('data');

        $this->assertTrue($data['marketing.social.listings_expanded']);
    }

    public function test_a_value_outside_the_permitted_set_is_refused(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'neon'],
        ])->assertStatus(422);

        // Refused loudly rather than stored: a preference the client cannot
        // render is worse than one that was never saved, because the second
        // tells somebody immediately.
        // withoutGlobalScopes: the request has ended and SetTenantContext clears
        // the tenant on its way out, so an assertion made afterwards has none to
        // be scoped by.
        $this->assertSame([], User::withoutGlobalScopes()->find($this->alpha['admin']->id)->preferences ?? []);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        // The schema is a platform invariant (§2.1 category 1). If the permitted
        // keys were themselves editable data, nothing would validate anything.
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.wallpaper' => 'kittens'],
        ])->assertStatus(422);
    }

    public function test_one_persons_preferences_are_their_own(): void
    {
        $other = app(\App\Support\Tenancy\TenantContext::class)->run(
            $this->alpha['organization'],
            fn () => User::create([
                'organization_id' => $this->alpha['organization']->id,
                'role_id'   => $this->alpha['admin']->role_id,
                'name'      => 'Other',
                'email'     => 'other@alpha.test',
                'password'  => 'a-real-password',
                'is_active' => true,
            ]),
        );

        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Same organization, same role — and none of that has anything to do
        // with what theme somebody likes.
        $theirs = $this->as('other@alpha.test')->getJson('/api/v1/me/preferences')
            ->assertOk()->json('data');

        $this->assertSame('system', $theirs['appearance.theme']);

        $this->assertSame([], User::withoutGlobalScopes()->find($other->id)->preferences ?? []);
    }

    // ── the two mixed modes, and a width that has bounds ─────────

    public function test_the_mixed_themes_are_accepted(): void
    {
        foreach (['mixed_dark_nav', 'mixed_light_nav'] as $mode) {
            $this->as()->patchJson('/api/v1/me/preferences', [
                'preferences' => ['appearance.theme' => $mode],
            ])->assertOk();

            $this->assertSame(
                $mode,
                User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                    ->preferences['appearance.theme'],
            );
        }
    }

    public function test_a_theme_that_does_not_exist_is_still_refused(): void
    {
        // The list grew; it did not stop being a list. A value the client
        // cannot render is worse than one that was never saved, because the
        // second tells somebody immediately.
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'neon'],
        ])->assertStatus(422);
    }

    public function test_a_sidebar_order_keeps_its_order_and_its_dividers(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => [
                'navigation.order' => ['customers', '--', 'dashboard'],
            ],
        ])->assertOk();

        $this->assertSame(
            ['customers', '--', 'dashboard'],
            $this->savedOrder(),
            'The order is positional data; nothing may re-sort it on the way in.'
        );
    }

    public function test_a_module_that_no_longer_exists_is_dropped_not_refused(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => [
                'navigation.order' => ['dashboard', 'retired_module', 'customers'],
            ],
        ])->assertOk();

        // `retired_module` stands for a deleted module (`tasks` was the example
        // until Task Board reused the key). Refusing would leave
        // anybody who had it in their saved order unable to save their sidebar
        // ever again, over a module that no longer exists — so it is dropped,
        // the same way an out-of-range width is clamped rather than rejected.
        $this->assertSame(['dashboard', 'customers'], $this->savedOrder());
    }

    public function test_a_duplicate_keeps_its_first_position(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => [
                'navigation.order' => ['customers', 'dashboard', 'customers'],
            ],
        ])->assertOk();

        // A module cannot be in two places in one list. First wins, because
        // that is where the person dragged it before the client got confused.
        $this->assertSame(['customers', 'dashboard'], $this->savedOrder());
    }

    public function test_dividers_may_repeat_where_modules_may_not(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => [
                'navigation.order' => ['--', 'dashboard', '--', '--'],
            ],
        ])->assertOk();

        // A divider is a POSITION rather than a thing, so the duplicate rule
        // does not apply to it. Leading and doubled rules are suppressed when
        // the sidebar DRAWS — it has to be anyway, because the search filter
        // produces both from an order that was perfectly good when saved.
        $this->assertSame(['--', 'dashboard', '--', '--'], $this->savedOrder());
    }

    public function test_something_that_is_not_a_list_saves_as_no_order_at_all(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.order' => 'dashboard'],
        ])->assertOk();

        $this->assertSame([], $this->savedOrder());
    }

    /** The stored order, read back from the row rather than from the response. */
    private function savedOrder(): array
    {
        return User::withoutGlobalScopes()->find($this->alpha['admin']->id)
            ->preferences['navigation.order'];
    }

    public function test_the_sidebar_width_is_clamped_rather_than_refused(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.width' => 9000],
        ])->assertOk();

        // Clamped, not rejected. This is somebody's own preference and an
        // out-of-range value is a stale field or a client bug, not an attack —
        // refusing would leave them looking at an error they cannot act on.
        $this->assertSame(
            420,
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['navigation.width'],
        );
    }

    public function test_a_width_below_the_floor_is_lifted_to_it(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.width' => 10],
        ])->assertOk();

        // 10px of sidebar is an app with no navigation. The floor is what stops
        // a hand-written API call producing one.
        $this->assertSame(
            180,
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['navigation.width'],
        );
    }

    /**
     * The two list widths Jose asked to survive leaving the tab (2026-09-29),
     * kept and bounded like the sidebar's.
     */
    public function test_the_leads_and_people_list_widths_are_kept_within_bounds(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['leads.list_percent' => 45, 'hr.records.list_width' => 5000],
        ])->assertOk();

        $stored = User::withoutGlobalScopes()->find($this->alpha['admin']->id)->preferences;

        $this->assertSame(45, $stored['leads.list_percent']);
        $this->assertSame(900, $stored['hr.records.list_width'], 'Wider than any list needs is clamped.');
    }
}
