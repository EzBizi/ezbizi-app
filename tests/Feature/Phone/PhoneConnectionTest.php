<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
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
            'kind' => 'subaccount',
        ]);

        $response->assertForbidden();
        $message = (string) $response->json('message');
        $this->assertStringContainsString('company', $message);
        $this->assertStringNotContainsString('PhoneConnection', $message);
    }

    /**
     * A connection's secrets must never come back out.
     *
     * Was written against BYO, which no longer exists — phone service is
     * EzBizi's own Twilio and a customer never types a credential. The
     * property survives the path it was written for: adoption still stores an
     * auth token and a minted API secret, and neither may reach JSON.
     */
    public function test_adopting_stores_the_account_and_never_returns_the_secret(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->subaccounts = [[
            'account_sid' => 'ACexistingexistingexistingexisting',
            'friendly_name' => $t['company']->name,
            'status' => 'active',
        ]];

        $this->grantPhoneService($t);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
            'account_sid' => 'ACexistingexistingexistingexisting',
            'auth_token' => $gateway->adoptableAuthToken,
        ])->assertCreated();

        $json = $response->getContent();
        $this->assertStringNotContainsString($gateway->adoptableAuthToken, $json);
        $this->assertArrayNotHasKey('credentials', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('auth_token', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('api_secret', $response->json('data') ?? []);

        $row = $this->inTenant($t['org'], fn () => PhoneConnection::query()->first());
        $this->assertSame($gateway->adoptableAuthToken, $row->auth_token);
        $this->assertNotSame(
            $gateway->adoptableAuthToken,
            $row->getAttributes()['auth_token'] ?? null,
            'The auth token was stored in the clear.',
        );
        $this->assertStringNotContainsString(
            $gateway->adoptableAuthToken,
            (string) json_encode($row),
        );
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
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failCreateTwimlAppWith = 'Twilio refused the voice application.';

        $this->grantPhoneService($t);

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
        ])->assertStatus(422);

        $row = $this->inTenant($t['org'], fn () => PhoneConnection::query()->first());
        $this->assertNotNull($row);
        $this->assertNull($row->twiml_app_sid);
        $this->assertContains('createSubaccount', array_column($gateway->calls, 'method'));
        $this->assertContains('createTwimlApp', array_column($gateway->calls, 'method'));
    }


    /**
     * The platform credentials the subaccount path needs. Not in phpunit.xml:
     * their ABSENCE is what test_missing_platform_env_names_the_gap asserts on,
     * so a global default would delete that test's meaning.
     */
    private function withPlatformTwilio(): void
    {
        config([
            'services.twilio.platform_account_sid' => 'ACplatformplatformplatformplatfor',
            'services.twilio.platform_api_key_sid' => 'SKplatformplatformplatformplatfor',
            'services.twilio.platform_api_secret' => 'platform-secret',
        ]);
    }

    /**
     * §4 gate 2: a company may only be activated on the provisioned path once
     * EzBizi has approved it by name. Without this the adopt tests below are
     * refused before they reach the code they are about.
     */
    private function grantPhoneService(array $t): void
    {
        $this->inTenant($t['org'], function () use ($t) {
            $request = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_APPROVED,
                'expected_volume' => 'under_100',
                'wants_sms' => false,
                'purpose' => 'Adopting the phone account this company already has.',
                'decided_at' => now(),
            ]);

            PhoneServiceRequestCompany::create([
                'request_id' => $request->id,
                'company_id' => $t['company']->id,
                'approved' => true,
            ]);
        });
    }

    /**
     * Adopting an existing phone account rather than minting a second one.
     *
     * Three subaccounts named after real companies already existed under the
     * platform account when this was written. Connecting without this branch
     * called accounts->create with the same FriendlyName and stranded the
     * original, numbers and all.
     */
    public function test_connecting_adopts_an_existing_subaccount_instead_of_creating_one(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->subaccounts = [[
            'account_sid' => 'ACexistingexistingexistingexisting',
            'friendly_name' => $t['company']->name,
            'status' => 'active',
        ]];

        $this->grantPhoneService($t);

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
            'account_sid' => 'ACexistingexistingexistingexisting',
            'auth_token' => $gateway->adoptableAuthToken,
        ])->assertCreated();

        $row = $this->inTenant($t['org'], fn () => PhoneConnection::query()->first());
        $this->assertSame('ACexistingexistingexistingexisting', $row->account_sid);

        $called = array_column($gateway->calls, 'method');
        $this->assertContains('adoptSubaccount', $called);

        // The whole point: no second account was made.
        $this->assertNotContains('createSubaccount', $called);
    }

    public function test_adopting_without_the_auth_token_says_why_it_is_needed(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->subaccounts = [[
            'account_sid' => 'ACexistingexistingexistingexisting',
            'friendly_name' => $t['company']->name,
            'status' => 'active',
        ]];

        $this->grantPhoneService($t);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
            'account_sid' => 'ACexistingexistingexistingexisting',
        ])->assertStatus(422);

        $message = (string) $response->json('errors.auth_token.0');
        $this->assertStringContainsString('auth token', $message);
        $this->assertStringNotContainsString('PhoneConnection', $message);
    }

    /**
     * The list must not answer "who else buys EzBizi phone service".
     *
     * Twilio returns every subaccount under the platform account. Handing that
     * to any tenant administrator names other customers. Adoption itself is
     * gated by the auth token; this is about the LIST being a question nobody
     * was entitled to have answered.
     */
    public function test_the_adoptable_list_hides_accounts_belonging_to_other_tenants(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->subaccounts = [
            [
                'account_sid' => 'ACmineminemineminemineminemineminx',
                'friendly_name' => $t['org']->name,
                'status' => 'active',
            ],
            [
                'account_sid' => 'ACtheirstheirstheirstheirstheirsxx',
                'friendly_name' => 'Somebody Else Plumbing',
                'status' => 'active',
            ],
        ];

        $response = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/connections/subaccounts')
            ->assertOk();

        $sids = array_column((array) $response->json('data'), 'account_sid');

        $this->assertContains('ACmineminemineminemineminemineminx', $sids);
        $this->assertNotContains('ACtheirstheirstheirstheirstheirsxx', $sids);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Somebody Else Plumbing', $body);
    }
}
