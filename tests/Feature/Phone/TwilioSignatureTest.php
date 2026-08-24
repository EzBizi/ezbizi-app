<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Public voice webhooks authenticate with X-Twilio-Signature against the
 * connection’s stored secret — not Sanctum, not the platform Twilio token.
 */
class TwilioSignatureTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_valid_signature_is_accepted(): void
    {
        $t = $this->provisionTenant();
        $token = 'connection-auth-token-alpha';
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'credentials' => $token,
        ]));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator($token))->computeSignature($url, $params);

        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('<Response', $response->getContent());
    }

    public function test_a_wrong_token_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'credentials' => 'the-real-connection-token',
        ]));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator('a-different-token'))->computeSignature($url, $params);

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
            'credentials' => 'alpha-token',
        ]));

        // Path names Beta's organization but Alpha's connection id — must 404
        // without revealing that the connection exists elsewhere.
        $url = $this->voiceUrl($beta['org']->id, $alphaConnection->id);
        $params = [
            'CallSid' => 'CAcccccccccccccccccccccccccccccccc',
            'From' => '+15551112222',
            'To' => '+15553334444',
        ];
        $signature = (new RequestValidator('alpha-token'))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertNotFound();
    }

    private function voiceUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/voice/'.$organizationId.'/'.$connectionId);
    }
}
