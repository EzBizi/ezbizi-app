<?php

namespace Tests\Feature\Phone;

use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Twilio Lookup v2 — what a number actually is.
 *
 * Every fresh lookup is billed, so the cache is not an optimisation here, it
 * is the difference between a screen somebody can use and a screen that bills
 * on every keystroke. The tests below are about the money as much as the data.
 */
class PhoneNumberQualityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_a_second_look_is_served_from_cache_and_costs_nothing(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $query = ['e164' => '+17865550123'];

        $first = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/lookup?'.http_build_query($query))
            ->assertOk();

        $this->assertFalse($first->json('cached'));
        $this->assertSame('mobile', $first->json('data.line_type'));
        $this->assertTrue($first->json('data.valid'));

        $second = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/lookup?'.http_build_query($query))
            ->assertOk();

        $this->assertTrue($second->json('cached'), 'the second look was billed again');
        $this->assertSame('mobile', $second->json('data.line_type'));

        // The point: Twilio was asked ONCE across two requests.
        $this->assertSame(
            1,
            collect($gateway->calls)->where('method', 'lookupDetailed')->count(),
        );
    }

    public function test_force_buys_a_fresh_answer(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($editor)->getJson('/api/v1/phone/lookup?e164=%2B17865550123')->assertOk();

        // A number that has been ported since the cached answer was taken.
        $gateway->detailedLookup = [
            'phone_number' => '+17865550123',
            'valid' => true,
            'national_format' => '(786) 555-0123',
            'country_code' => 'US',
            'line_type' => 'landline',
            'carrier_name' => 'Someone Else',
            'caller_name' => null,
        ];

        $forced = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/lookup?e164=%2B17865550123&force=1')
            ->assertOk();

        $this->assertFalse($forced->json('cached'));
        $this->assertSame('landline', $forced->json('data.line_type'));
        $this->assertSame(
            2,
            collect($gateway->calls)->where('method', 'lookupDetailed')->count(),
        );
    }

    /**
     * "We could not ask" and "that number is not real" are different answers,
     * and conflating them is how somebody deletes a customer's good number.
     */
    public function test_twilio_being_unreachable_is_not_the_same_as_an_invalid_number(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->lookupEnabled = false;

        $response = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/lookup?e164=%2B17865550123')
            ->assertOk();

        $this->assertNull($response->json('data'));
        $this->assertNotNull($response->json('message'));
    }

    public function test_looking_a_number_up_needs_the_authority_that_pays_for_it(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view', 'phone.settings.view']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/lookup?e164=%2B17865550123')
            ->assertForbidden();
    }
}
