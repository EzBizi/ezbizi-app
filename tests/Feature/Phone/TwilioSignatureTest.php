<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Public voice webhooks authenticate with X-Twilio-Signature against the
 * connection’s Account Auth Token — not the API key secret, not Sanctum,
 * not the platform Twilio token.
 */
class TwilioSignatureTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_valid_signature_is_accepted(): void
    {
        $t = $this->provisionTenant();
        $authToken = 'connection-auth-token-alpha';
        $apiSecret = 'SK-api-key-secret-not-for-signing';
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'credentials' => $apiSecret,
            'auth_token' => $authToken,
        ]));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('<Response', $response->getContent());
    }

    public function test_a_signature_built_with_the_api_key_secret_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        $authToken = 'the-real-account-auth-token';
        $apiSecret = 'SK-api-key-secret-wrong-for-hmac';
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'credentials' => $apiSecret,
            'auth_token' => $authToken,
        ]));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        // Twilio signs with the Account Auth Token; an HMAC made from the API
        // key secret must not pass even when that secret is stored on the row.
        $signature = (new RequestValidator($apiSecret))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');
    }

    public function test_a_wrong_auth_token_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'credentials' => 'SK-api-secret',
            'auth_token' => 'the-real-account-auth-token',
        ]));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CAdddddddddddddddddddddddddddddddd',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator('a-different-auth-token'))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');
    }

    public function test_another_organizations_connection_is_not_found(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $alphaConnection = $this->inTenant($alpha['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $alpha['org']->id,
            'credentials' => 'SK-alpha',
            'auth_token' => 'alpha-auth-token',
        ]));

        // Path names Beta's organization but Alpha's connection id — must 404
        // without revealing that the connection exists elsewhere.
        $url = $this->voiceUrl($beta['org']->id, $alphaConnection->id);
        $params = [
            'CallSid' => 'CAcccccccccccccccccccccccccccccccc',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator('alpha-auth-token'))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertNotFound();
    }

    // Moving to a new Twilio account (11-19): a number not yet moved signs
    // with the old token, so both pass, and an unrelated token still does not.
    public function test_the_previous_auth_token_also_validates_during_the_move(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'auth_token' => 'new-account-token',
            'previous_auth_token' => 'old-account-token',
        ]));
        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = ['CallSid' => 'CAcccccccccccccccccccccccccccccccc', 'From' => '+15551112222', 'To' => '+15553334444'];
        $sign = fn (string $token) => ['X-Twilio-Signature' => (new RequestValidator($token))->computeSignature($url, $params)];

        $this->post($url, $params, $sign('new-account-token'))->assertOk();
        $this->post($url, $params, $sign('old-account-token'))->assertOk();
        $this->post($url, $params, $sign('somebody-elses-token'))->assertForbidden();
    }

    private function voiceUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/voice/'.$organizationId.'/'.$connectionId);
    }
}
