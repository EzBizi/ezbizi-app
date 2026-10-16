<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class GeocodeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
        config(['services.google_maps.server_key' => 'test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => '1 Main St, Hartford, CT 06103, USA',
            'address_components' => [['short_name' => '06103', 'types' => ['postal_code']]],
            'geometry' => ['location' => ['lat' => 41.7637, 'lng' => -72.6851], 'location_type' => 'ROOFTOP'],
        ]]])]);
    }

    public function test_it_returns_the_place_with_coordinates(): void
    {
        $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/geocode?for=time_clock&address=1+main+st+hartford')
            ->assertOk()
            ->assertJsonPath('data.address', '1 Main St, Hartford, CT 06103, USA')
            ->assertJsonPath('data.zip', '06103')
            ->assertJsonPath('data.lat', 41.7637)
            ->assertJsonPath('data.lng', -72.6851)
            ->assertJsonPath('data.exact', true);
    }

    public function test_each_caller_needs_its_own_screens_permission(): void
    {
        $viewer = $this->userWithRole($this->alpha, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/geocode?for=customers&address=1+main+st')->assertOk();
        $this->signedInAs($viewer)->getJson('/api/v1/geocode?for=time_clock&address=1+main+st')->assertForbidden();
    }
}
