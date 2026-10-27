<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AddressLookupController;
use App\Http\Controllers\Api\CountyLookupController;
use App\Http\Controllers\Api\SchedulingLocationsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Photon is an add-on, not a dependency (Jose, 2026-10-27): a timeout or an
 * unreachable host degrades to no suggestions with a sentence, never a 500.
 */
class PhotonUnreachableTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['photon.komoot.io/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
    }

    public function test_address_lookup_timeout_is_empty_with_a_sentence(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])->getJson('/api/v1/address-lookup?q=500+Main')
            ->assertOk()
            ->assertExactJson(['data' => [], 'message' => AddressLookupController::UNAVAILABLE]);
    }

    public function test_county_lookup_timeout_is_empty_with_a_sentence(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])->getJson('/api/v1/places/counties?state=Florida&q=brow')
            ->assertOk()
            ->assertExactJson(['data' => [], 'message' => CountyLookupController::UNAVAILABLE]);
    }

    public function test_nearest_branch_timeout_is_a_422_sentence_on_q_not_a_500(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])->getJson('/api/v1/scheduling/locations/nearest?q=75201')
            ->assertStatus(422)
            ->assertJsonPath('errors.q.0', SchedulingLocationsController::GEOCODER_UNAVAILABLE);

        // The branch list itself does not touch Photon and keeps working.
        $this->signedInAs($t['admin'])->getJson('/api/v1/scheduling/locations')->assertOk();
    }
}
