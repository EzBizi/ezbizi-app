<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AddressLookupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_suggestions_fill_the_same_fields_a_company_has(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'properties' => [
                        'name'        => '10 Main Street',
                        'street'      => 'Main Street',
                        'housenumber' => '10',
                        'city'        => 'Newark',
                        'statecode'   => 'NJ',
                        'postcode'    => '07102',
                    ],
                ]],
            ]),
        ]);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/address-lookup?q=10%20Main')
            ->assertOk()
            ->assertJsonPath('data.0.label', '10 Main Street')
            ->assertJsonPath('data.0.address_line1', '10 Main Street')
            ->assertJsonPath('data.0.city', 'Newark')
            ->assertJsonPath('data.0.state', 'NJ')
            ->assertJsonPath('data.0.postal_code', '07102');
    }

    public function test_a_structured_business_address_formats_the_line_contracts_print(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/organization', [
                'address_line1' => '10 Main Street',
                'city'          => 'Newark',
                'state'         => 'nj',
                'postal_code'   => '07102',
            ])
            ->assertOk()
            ->assertJsonPath('data.address_line1', '10 Main Street')
            ->assertJsonPath('data.city', 'Newark')
            ->assertJsonPath('data.state', 'NJ')
            ->assertJsonPath('data.address', '10 Main Street, Newark NJ 07102');
    }

    public function test_a_short_query_is_not_sent_out(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        Http::fake();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/address-lookup?q=ab')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertNothingSent();
    }
}
