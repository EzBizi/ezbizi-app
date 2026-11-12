<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\EmergencyAddress;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Each line's 911 address comes from its company on file, without anybody typing it (2026-11-12). */
class PhoneEmergencyAddressTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_line_gets_its_companys_address_and_one_without_says_so(): void
    {
        $t = $this->provisionTenant();
        /** @var FakeTwilioGateway $twilio */
        $twilio = $this->app->make(TwilioGateway::class);

        $this->inTenant($t['org'], function () use ($t, $twilio) {
            $connection = PhoneConnection::factory()->create(['scope_type' => 'company', 'scope_id' => $t['company']->id]);
            $line = fn (string $e164) => PhoneNumber::factory()->create([
                'connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id, 'e164' => $e164, 'twilio_sid' => 'PN'.substr($e164, 2),
            ]);

            $bare = $line('+13055550201');
            $this->assertSame(EmergencyAddress::NO_ADDRESS, EmergencyAddress::attach($bare, $twilio));
            $this->assertFalse((bool) $bare->fresh()->emergency_address_set);

            $t['company']->forceFill(['address_line1' => '1 Main St', 'city' => 'Miami', 'state' => 'FL', 'postal_code' => '33101'])->save();
            $this->assertNull(EmergencyAddress::attach($bare->fresh(), $twilio));
            $this->assertTrue((bool) $bare->fresh()->emergency_address_set);

            // A second line at the same premises reuses the registered address.
            $this->assertNull(EmergencyAddress::attach($line('+13055550202'), $twilio));
            $this->assertCount(1, collect($twilio->calls)->where('method', 'createAddress'));
            $this->assertSame('1 Main St', collect($twilio->calls)->firstWhere('method', 'createAddress')['args'][1]['street']);
        });
    }
}
