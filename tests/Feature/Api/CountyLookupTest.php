<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The county picker under a chosen state (Jose, 2026-10-01). */
class CountyLookupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_counties_come_from_the_chosen_state_only_without_the_word_county(): void
    {
        $t = $this->provisionTenant('Alpha');
        $county = fn (string $name, string $state, string $cc = 'US') => ['properties' => [
            'name' => $name, 'state' => $state, 'countrycode' => $cc,
        ]];
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [
            $county('Broward County', 'Florida'),
            $county('Broward', 'Florida'),
            $county('Brown County', 'Texas'),
        ]])]);

        $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?state=Florida&q=brow')
            ->assertOk()->assertExactJson(['data' => ['Broward']]);

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'q=brow, Florida')
            && str_contains($r->url(), 'layer=county'));

        // No state chosen yet: nothing to offer, and nothing asked of Photon.
        $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?q=brow')
            ->assertOk()->assertExactJson(['data' => []]);
        Http::assertSentCount(1);
    }
}
