<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhonePortRequest;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-05: port in, "showing pin codes and verification as well".
 */
class PhonePortCarrierTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CARRIER = [
        'customer_type' => 'Business',
        'customer_name' => 'A-1 Chimney',
        'account_number' => '55501234',
        'account_telephone_number' => '+19544538774',
        'authorized_representative' => 'Jose Demasi',
        'authorized_representative_email' => 'jose@example.com',
        'address' => [
            'street' => '101 Spear St',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33101',
            'country' => 'US',
        ],
    ];

    public function test_the_carrier_details_and_pin_go_to_twilio_and_come_back_on_the_list(): void
    {
        $twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $twilio);

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $this->signedInAs($editor)->postJson('/api/v1/phone/ports', [
            'connection_id' => $connection->id,
            'e164s' => ['+19544538774'],
            'losing_carrier' => self::CARRIER,
            'pin' => '4321',
        ])->assertCreated();

        $sent = collect($twilio->calls)->firstWhere('method', 'startPort')['args'][2];
        $this->assertSame('4321', $sent['pin']);
        $this->assertSame('A-1 Chimney', $sent['losing_carrier']['customer_name']);

        // Encrypted at rest: the raw column is not the PIN in clear.
        $raw = $this->inTenant($t['org'], fn () => DB::table('phone_port_requests')->value('carrier'));
        $this->assertStringNotContainsString('4321', (string) $raw);

        $row = $this->signedInAs($editor)->getJson('/api/v1/phone/ports')->assertOk()->json('data.0');
        $this->assertSame('4321', $row['carrier']['pin']);
        $this->assertSame('55501234', $row['carrier']['losing_carrier']['account_number']);
    }

    public function test_carrier_details_without_who_signs_are_refused(): void
    {
        $this->app->instance(TwilioGateway::class, new FakeTwilioGateway);

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $carrier = self::CARRIER;
        unset($carrier['authorized_representative_email']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/ports', [
            'connection_id' => $connection->id,
            'e164s' => ['+19544538774'],
            'losing_carrier' => $carrier,
        ])->assertUnprocessable()->assertJsonValidationErrors('losing_carrier.authorized_representative_email');

        $this->assertSame(0, $this->inTenant($t['org'], fn () => PhonePortRequest::query()->count()));
    }
}
