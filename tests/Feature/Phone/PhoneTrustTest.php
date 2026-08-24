<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Trust Hub status on a connection (Task 13).
 *
 * Settings polls GET .../trust. Missing Twilio profiles must not 500 — Fake
 * (and live with none) return pending status strings for Settings to show.
 */
class PhoneTrustTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_trust_get_does_not_500_without_a_profile(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Trust viewer', ['phone.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            return PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($editor)->getJson(
            '/api/v1/phone/connections/'.$connection->id.'/trust',
        );

        $response->assertOk();
        $this->assertSame(200, $response->status());
        $this->assertSame('pending', $response->json('data.customer_profile'));
        $this->assertSame('pending', $response->json('data.shaken_stir'));
        $this->assertSame('pending', $response->json('data.cnam'));
        $this->assertSame('pending', $response->json('data.voice_integrity'));
        $this->assertContains('trustStatus', array_column($gateway->calls, 'method'));
        $this->assertArrayNotHasKey('credentials', $response->json('data') ?? []);
    }

    public function test_trust_post_submits_and_returns_pending(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Trust editor', ['phone.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            return PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($editor)->postJson(
            '/api/v1/phone/connections/'.$connection->id.'/trust',
            [
                'email' => 'ops@example.com',
                'friendly_name' => 'EzBizi trust',
            ],
        );

        $response->assertOk();
        $this->assertSame('pending', $response->json('data.customer_profile'));
        $this->assertContains('submitTrust', array_column($gateway->calls, 'method'));
    }
}
