<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The county picker under a chosen state (Jose, 2026-10-01; Census list since 2026-11-05). */
class CountyLookupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_state_gives_its_whole_county_list_and_q_filters_it(): void
    {
        Http::preventStrayRequests();
        $t = $this->provisionTenant('Alpha');

        // Merav's case: Suffolk, Massachusetts, which the old search never found.
        $all = $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?state=Massachusetts')->assertOk()->json('data');
        $this->assertCount(14, $all);
        $this->assertContains('Suffolk', $all);

        $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?state=florida&q=brow')
            ->assertOk()->assertExactJson(['data' => ['Broward']]);

        $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?q=brow')
            ->assertOk()->assertExactJson(['data' => []]);
    }
}
