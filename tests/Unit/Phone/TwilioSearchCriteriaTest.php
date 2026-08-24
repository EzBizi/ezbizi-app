<?php

namespace Tests\Unit\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\HttpTwilioGateway;
use Tests\TestCase;

/**
 * searchAvailable callers use snake_case; Twilio’s SDK wants camelCase.
 * Passing area_code through unmapped yields an unfiltered US list.
 */
class TwilioSearchCriteriaTest extends TestCase
{
    public function test_snake_case_search_keys_become_twilio_camel_case(): void
    {
        $mapped = HttpTwilioGateway::normalizeSearchCriteria([
            'area_code' => '203',
            'voice' => true,
            'sms' => true,
            'contains' => '555',
            'country' => 'US',
        ]);

        $this->assertSame([
            'areaCode' => '203',
            'voiceEnabled' => true,
            'smsEnabled' => true,
            'contains' => '555',
            'country' => 'US',
        ], $mapped);
        $this->assertArrayNotHasKey('area_code', $mapped);
        $this->assertArrayNotHasKey('voice', $mapped);
        $this->assertArrayNotHasKey('sms', $mapped);
    }

    public function test_the_fake_gateway_records_mapped_keys_not_snake_case(): void
    {
        $fake = new FakeTwilioGateway;
        $connection = new PhoneConnection;
        $connection->id = '00000000-0000-0000-0000-000000000001';

        $fake->searchAvailable($connection, [
            'area_code' => '860',
            'voice' => true,
        ]);

        $this->assertSame('searchAvailable', $fake->calls[0]['method']);
        $recorded = $fake->calls[0]['args'][1];
        $this->assertSame('860', $recorded['areaCode']);
        $this->assertTrue($recorded['voiceEnabled']);
        $this->assertArrayNotHasKey('area_code', $recorded);
        $this->assertArrayNotHasKey('voice', $recorded);
    }
}
