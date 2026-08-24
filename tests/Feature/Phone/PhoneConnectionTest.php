<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connect BYO / provisioned Twilio at organization or company (spec §4–§5).
 *
 * Authority never flows upward: franchise assignment cannot connect the company.
 * Secrets never appear in JSON.
 */
class PhoneConnectionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_franchise_administrator_cannot_connect_the_company(): void
    {
        $t = $this->provisionTenant();
        $branch = $this->userWithRole(
            $t,
            'Branch phone',
            ['phone.view', 'phone.edit'],
            onlyAt: $t['franchise'],
        );

        $response = $this->signedInAs($branch)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'byo',
            'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'api_secret' => 'SK-secret-value',
        ]);

        $response->assertForbidden();
        $message = (string) $response->json('message');
        $this->assertStringContainsString('company', $message);
        $this->assertStringNotContainsString('PhoneConnection', $message);
    }

    public function test_byo_stores_the_account_and_never_returns_the_secret(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $secret = 'SK-secret-never-leak';

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'kind' => 'byo',
            'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'api_secret' => $secret,
        ]);

        $response->assertCreated();
        $json = $response->getContent();
        $this->assertStringNotContainsString($secret, $json);
        $this->assertArrayNotHasKey('credentials', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('api_secret', $response->json('data') ?? []);

        $methods = array_column($gateway->calls, 'method');
        $this->assertContains('verifyAccount', $methods);
        $this->assertContains('createTwimlApp', $methods);

        $row = $this->inTenant($t['org'], fn () => PhoneConnection::query()->first());
        $this->assertNotNull($row);
        $this->assertSame($secret, $row->credentials);
        $this->assertNotSame($secret, $row->getAttributes()['credentials'] ?? null);
        $this->assertStringNotContainsString($secret, (string) json_encode($row));
    }

    public function test_missing_platform_env_names_the_gap(): void
    {
        config([
            'services.twilio.platform_account_sid' => null,
            'services.twilio.platform_api_key_sid' => null,
            'services.twilio.platform_api_secret' => null,
        ]);

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'kind' => 'subaccount',
        ])
            ->assertStatus(501)
            ->assertJsonPath(
                'message',
                'EzBizi has not been given a Twilio account to create phone service with.',
            );
    }

    /**
     * A TwiML App failure must not roll back the saved connection: the row is
     * what Settings uses to disconnect orphaned Twilio resources. The old
     * in-transaction order left nothing to clean up.
     */
    public function test_a_failed_twiml_app_still_leaves_a_disconnectable_connection(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failCreateTwimlAppWith = 'Twilio refused the voice application.';

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'kind' => 'byo',
            'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'api_secret' => 'SK-secret-kept',
        ])->assertStatus(422);

        $row = $this->inTenant($t['org'], fn () => PhoneConnection::query()->first());
        $this->assertNotNull($row);
        $this->assertSame('ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $row->account_sid);
        $this->assertNull($row->twiml_app_sid);
        $this->assertContains('verifyAccount', array_column($gateway->calls, 'method'));
        $this->assertContains('createTwimlApp', array_column($gateway->calls, 'method'));
    }
}
