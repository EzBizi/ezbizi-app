<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Shopping for a line: GET /phone/numbers/available.
 *
 * Buying used to be an area code and `available[0]`. This is the screen that
 * lets somebody choose, so the filters have to actually reach Twilio and the
 * answer has to carry enough to choose ON — above all whether the number can
 * do SMS, and whether it needs a registered address before it can be bought.
 */
class PhoneNumberSearchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $t): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));
    }

    public function test_every_filter_reaches_twilio_in_its_own_spelling(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $this->connect($t);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($editor)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'type' => 'local',
            'country' => 'US',
            'area_code' => '561',
            'contains' => 'CHIM',
            'sms' => 'true',
            'in_region' => 'FL',
            'in_locality' => 'Jupiter',
            'near_lat_long' => '26.93,-80.09',
            'distance' => 50,
            'exclude_all_address_required' => 'true',
        ]))->assertOk();

        $search = collect($gateway->calls)->firstWhere('method', 'searchAvailable');
        $this->assertNotNull($search, 'the search never reached the gateway');

        // camelCase is Twilio's spelling. A key left in snake_case is not an
        // error there — read() drops what it does not know, and the search
        // comes back unfiltered.
        $sent = $search['args'][1];
        $this->assertSame('561', $sent['areaCode']);
        $this->assertSame('CHIM', $sent['contains']);
        $this->assertSame('FL', $sent['inRegion']);
        $this->assertSame('Jupiter', $sent['inLocality']);
        $this->assertSame('26.93,-80.09', $sent['nearLatLong']);
        $this->assertSame(50, $sent['distance']);
        $this->assertTrue($sent['excludeAllAddressRequired']);
    }

    public function test_a_hit_says_what_it_can_do_and_what_it_would_need(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $this->connect($t);

        $response = $this->signedInAs($editor)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '561',
        ]))->assertOk();

        $first = $response->json('data.0');
        $this->assertSame('+15610101001', $first['phone_number']);
        $this->assertSame('Testville', $first['locality']);
        $this->assertTrue($first['capabilities']['sms']);
        $this->assertSame('none', $first['address_requirements']);
    }

    public function test_toll_free_reads_the_toll_free_list_not_the_local_one(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $this->connect($t);

        $response = $this->signedInAs($editor)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'type' => 'toll_free',
        ]))->assertOk();

        $this->assertSame('+18330101001', $response->json('data.0.phone_number'));
    }

    public function test_a_rate_center_without_its_lata_is_refused_here_not_by_twilio(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $this->connect($t);

        $this->signedInAs($editor)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'in_rate_center' => 'JUPITER',
        ]))->assertStatus(422)->assertJsonValidationErrors('in_lata');
    }

    public function test_searching_needs_the_authority_to_buy(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view', 'phone.settings.view']);
        $this->connect($t);

        $this->signedInAs($viewer)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '561',
        ]))->assertForbidden();
    }

    public function test_twilio_being_unreachable_is_a_sentence_not_a_500(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $this->connect($t);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failSearchAvailableWith = 'network is down';

        $response = $this->signedInAs($editor)->getJson('/api/v1/phone/numbers/available?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'area_code' => '561',
        ]))->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertNotNull($response->json('message'));
    }
}
