<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Customer Experience — Reviews and Business Profile, moved out of Marketing
 * on 2026-09-25 (Jose). Nesting is a layout fact, not a grant, in both directions.
 */
class CustomerExperienceModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_is_top_level_and_holds_reviews_and_business_profile(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertNull($registry->get('customer_experience')?->parent);
        $this->assertSame(
            ['reviews', 'business_profile'],
            array_map(fn ($m) => $m->key, $registry->childrenOf('customer_experience')),
        );
    }

    public function test_its_grant_and_its_childrens_grants_are_independent(): void
    {
        $t = $this->provisionTenant('Alpha');

        $container = $this->userWithRole($t, 'Container', ['customer_experience.view']);
        $modules = collect(
            $this->signedInAs($container)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($modules['customer_experience']['reachable']);
        $this->assertFalse($modules['reviews']['reachable']);
        $this->assertFalse($modules['business_profile']['reachable']);
        $this->assertSame('customer_experience', $modules['reviews']['parent']);

        // The control: a child's own grant reaches it, and does not carry the container.
        $reader = $this->userWithRole($t, 'Reviews', ['reviews.view'], 'reviews@alpha.test');
        $theirs = collect(
            $this->signedInAs($reader)->getJson('/api/v1/me')->assertOk()->json('data.modules')
        )->keyBy('key');

        $this->assertTrue($theirs['reviews']['reachable']);
        $this->assertFalse($theirs['customer_experience']['reachable']);
    }
}
