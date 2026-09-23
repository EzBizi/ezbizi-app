<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1Leads GBP — where Reviews and Business Profile are drawn, and nothing else.
 *
 * ── What moved, and what deliberately did not ────────────────────
 *
 * Jose, 2026-09-23: *"this is a temporary thing as we will replace his system
 * once we get our own GBP, but till then, we need to move it, which is why i
 * want it separate for now, in fact, just make it as it's own main tab."*
 *
 * So the two modules moved out of Marketing and into a container of their own.
 * A move like this is one word per module in the registry and it is exactly the
 * kind of change that looks harmless and takes somebody's screen away: the
 * client decides what to draw from `parent` on `/me`, and a role that reaches a
 * child but not the container is the common case rather than the odd one.
 *
 * So this file asserts both halves — the layout the client reads, and that
 * nobody's access moved with it.
 *
 * The client's own container is tested in `a1leads_gbp_screen_test.dart`
 * against a hand fixture. This is what holds that fixture to the real registry.
 */
class A1LeadsGbpModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The layout `/me` reports, which is the only thing the client reads.
     *
     * Asserted through the ENDPOINT rather than off the registry object,
     * because the registry being right and the payload dropping the field are
     * different failures and only one of them is visible on screen.
     */
    public function test_me_draws_both_modules_inside_the_container(): void
    {
        $t = $this->provisionTenant('Alpha');

        $modules = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertSame('a1leads_gbp', $modules['reviews']['parent']);
        $this->assertSame('a1leads_gbp', $modules['business_profile']['parent']);

        // And the container is a destination rather than a tab inside one.
        // Nesting is one level, so a container with a parent would put its two
        // children two deep and the registry refuses that — this says the
        // intended shape out loud rather than relying on that refusal.
        $this->assertNull($modules['a1leads_gbp']['parent']);
    }

    /**
     * NESTING IS A LAYOUT FACT, NOT A GRANT.
     *
     * The failure this guards is silent and one-directional: if reaching
     * Reviews came to depend on the container's own permission, every existing
     * role would keep `reviews.view` and lose the screen, with nothing logged
     * and nothing to quote. The reverse — the container granting its children —
     * would be a widening, and is asserted in the same breath.
     */
    public function test_a_role_holding_one_child_reaches_it_and_not_the_container(): void
    {
        $t = $this->provisionTenant('Bravo');

        $reader = $this->userWithRole($t, 'Reviews reader', ['reviews.view']);

        $modules = collect(
            $this->signedInAs($reader)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['reviews']['reachable'], 'the grant they hold');
        $this->assertFalse(
            $modules['a1leads_gbp']['reachable'],
            'the container is not granted by its child - the client draws the row anyway'
        );
        $this->assertFalse(
            $modules['business_profile']['reachable'],
            'and a sibling is not granted by being drawn in the same strip'
        );
    }

    /**
     * The container's own grant opens nothing behind it.
     *
     * The other direction of the same rule, and the one an administrator is
     * most likely to try: ticking the row that carries the name.
     */
    public function test_the_container_grant_alone_reaches_neither_child(): void
    {
        $t = $this->provisionTenant('Charlie');

        $holder = $this->userWithRole($t, 'Container only', ['a1leads_gbp.view']);

        $modules = collect(
            $this->signedInAs($holder)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['a1leads_gbp']['reachable']);
        $this->assertFalse($modules['reviews']['reachable']);
        $this->assertFalse($modules['business_profile']['reachable']);
    }

    /**
     * THE POSITIVE CONTROL, and the suite is worth little without it.
     *
     * Every assertion above is about a flag on a payload. This is the one that
     * says the permission still opens the door it always opened — a move that
     * quietly broke the endpoints would satisfy all of them.
     */
    public function test_neither_endpoint_changed_hands(): void
    {
        $t = $this->provisionTenant('Delta');

        $this->signedInAs($this->userWithRole($t, 'Reviews', ['reviews.view']))
            ->getJson('/api/v1/reviews')
            ->assertOk();

        $this->signedInAs($this->userWithRole($t, 'Profile', ['business_profile.view']))
            ->getJson('/api/v1/business-profile/locations')
            ->assertOk();
    }

    /**
     * The container holds exactly these two, derived rather than listed.
     *
     * Written against the registry because that is where a third module would
     * be added, and the point is to notice: this container is temporary and
     * everything put inside it has to come out again when EzBizi holds its own
     * Business Profile API access (docs/BUSINESS-PROFILE.md §9b).
     */
    public function test_nothing_else_is_drawn_in_the_container(): void
    {
        $inside = collect(app(ModuleRegistry::class)->all())
            ->filter(fn ($module) => $module->parent === 'a1leads_gbp')
            ->map(fn ($module) => $module->key)
            ->values()
            ->all();

        $this->assertSame(['reviews', 'business_profile'], $inside);
    }
}
