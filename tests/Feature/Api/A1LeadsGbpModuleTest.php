<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1Leads GBP is a new module beside ours, not a home for ours.
 *
 * ── The mistake this file exists to prevent from recurring ───────
 *
 * The first version of this module made `reviews` and `business_profile` its
 * children. That was wrong, and Jose said so in the clearest terms available:
 *
 * > *"i didn't ask u to modify our current system, i said we are integrating
 * > Rodrigo's system as a different thing, we still need our system in place
 * > for when we get OUR GBP Credentials and we'll need the google reviewers to
 * > see it. SO PUT IT BACK WHERE IT WAS, THE A1LEADS GBP TAB IS A NEW ENTIRE
 * > THING."*
 *
 * The two are not interchangeable and the difference is not cosmetic. EzBizi's
 * Reviews and Business Profile read EzBizi's own tables and are what a Google
 * reviewer is shown when our own API access arrives. A1Leads GBP is a
 * read-only window onto somebody else's system and is deleted when that access
 * lands. Nesting ours inside it made the thing we are judged on look like a
 * feature of a temporary bridge.
 *
 * So the assertions below are mostly about what this module does NOT contain,
 * paired with the presences that make those absences readable.
 */
class A1LeadsGbpModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * It stands on its own and holds nothing.
     *
     * Derived from the registry rather than naming the two modules, so a THIRD
     * module parented here later fails this as well — which is the shape the
     * mistake took the first time.
     */
    public function test_the_module_is_top_level_and_has_no_children(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertNotNull(
            $registry->get('a1leads_gbp'),
            'the module is missing entirely',
        );

        $this->assertNull(
            $registry->get('a1leads_gbp')?->parent,
            'it is a main tab, not a chip inside another module',
        );

        $inside = collect($registry->all())
            ->filter(fn ($module) => $module->parent === 'a1leads_gbp')
            ->map(fn ($module) => $module->key)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $inside,
            'nothing is drawn inside A1Leads GBP - it is a window onto another system, '
            .'not a container for ours',
        );
    }

    /**
     * And ours are in EzBizi's own Customer Experience tab (2026-09-25), never here.
     *
     * The other half, and the half that actually matters to the business: this
     * is the placement a Google reviewer is walked through.
     */
    public function test_our_own_modules_are_inside_customer_experience(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame('customer_experience', $registry->get('reviews')?->parent);
        $this->assertSame('customer_experience', $registry->get('business_profile')?->parent);
    }

    /**
     * Through the endpoint, because that is what the client reads.
     *
     * The registry being right and the payload dropping the field are different
     * failures and only one of them is visible on screen.
     */
    public function test_me_reports_the_same_placement(): void
    {
        $t = $this->provisionTenant('Alpha');

        $modules = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertNull($modules['a1leads_gbp']['parent']);
        $this->assertSame('customer_experience', $modules['reviews']['parent']);
        $this->assertSame('customer_experience', $modules['business_profile']['parent']);
    }

    /**
     * Its grant opens it and nothing else, in both directions.
     *
     * The failure worth guarding is a bridge module quietly carrying access to
     * the real ones, or the reverse — somebody granted Reviews finding they
     * have also been given a window onto another company's system.
     */
    public function test_its_grant_is_independent_of_ours(): void
    {
        $t = $this->provisionTenant('Bravo');

        $bridge = $this->userWithRole($t, 'Bridge only', ['a1leads_gbp.view']);

        $modules = collect(
            $this->signedInAs($bridge)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['a1leads_gbp']['reachable']);
        $this->assertFalse($modules['reviews']['reachable']);
        $this->assertFalse($modules['business_profile']['reachable']);

        $reader = $this->userWithRole($t, 'Reviews only', ['reviews.view']);

        $theirs = collect(
            $this->signedInAs($reader)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($theirs['reviews']['reachable']);
        $this->assertFalse($theirs['a1leads_gbp']['reachable']);
    }

    /**
     * THE POSITIVE CONTROL: our endpoints still answer our permissions.
     *
     * Every assertion above is about a flag or an absence, and a module move
     * that quietly broke the real screens would satisfy all of them.
     */
    public function test_our_endpoints_are_untouched(): void
    {
        $t = $this->provisionTenant('Charlie');

        $this->signedInAs($this->userWithRole($t, 'Reviews', ['reviews.view']))
            ->getJson('/api/v1/reviews')
            ->assertOk();

        $this->signedInAs($this->userWithRole($t, 'Profile', ['business_profile.view']))
            ->getJson('/api/v1/business-profile/locations')
            ->assertOk();
    }
}
