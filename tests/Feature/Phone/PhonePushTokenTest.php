<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\HttpTwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A phone rings because a push woke it. Nothing else can.
 *
 * ── The defect ───────────────────────────────────────────────────
 *
 * Jose installed a TestFlight build on 2026-09-09 and calls did not ring. The
 * device was registered, outgoing calls worked, and every screen said the desk
 * was connected — because outgoing needs only the TwiML application, which was
 * set.
 *
 * Incoming is different. Twilio cannot open a socket to a sleeping iPhone; it
 * sends a VoIP push, and it can only do that if the ACCESS TOKEN names a push
 * credential. Ours named none, so there was nothing to push to and no error
 * anywhere: the call rang the caller and reached no phone.
 *
 * ── Why the credential lives on the connection ───────────────────
 *
 * A push credential belongs to the Twilio account that mints the token, and
 * EzBizi mints from the COMPANY's subaccount. So one credential per company,
 * on `phone_connections` beside the API key it is used with — not a platform
 * constant, which would be a credential on the wrong account and refused.
 *
 * ── Why the JWT is decoded rather than the gateway mocked ────────
 *
 * The token is the artefact Twilio reads. A test asserting "the gateway was
 * asked for a token" passes just as happily when the grant inside it is empty,
 * which is precisely the state that shipped.
 */
class PhonePushTokenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array<string, mixed> the voice grant inside a minted token */
    private function voiceGrantOf(PhoneConnection $connection, ?string $platform): array
    {
        $jwt = (new HttpTwilioGateway)->accessToken($connection, 'user-1', $platform);

        [, $payload] = explode('.', $jwt);
        $claims = json_decode(
            base64_decode(strtr($payload, '-_', '+/')),
            true,
        );

        return $claims['grants']['voice'] ?? [];
    }

    private function connection(array $t, array $attributes = []): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id,
            'status' => PhoneConnection::STATUS_CONNECTED,
            'api_key_sid' => 'SK'.str_repeat('a', 32),
            'credentials' => 'a-secret-that-signs-the-token',
            'twiml_app_sid' => 'AP'.str_repeat('c', 32),
            ...$attributes,
        ]));
    }

    /**
     * An iPhone's token names the credential that can wake it.
     *
     * Without this claim the token is still valid, the device still registers,
     * and the phone never rings — which is the whole failure.
     */
    public function test_an_ios_token_carries_the_push_credential(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, [
            'push_credential_ios_sid' => 'CR'.str_repeat('1', 32),
            'push_credential_android_sid' => 'CR'.str_repeat('2', 32),
        ]);

        $grant = $this->voiceGrantOf($connection, 'ios');

        $this->assertSame(
            'CR'.str_repeat('1', 32),
            $grant['push_credential_sid'] ?? null,
            'the token named no push credential, so Twilio had nothing to wake '
            .'the phone with and the call reached no device',
        );

        // The ANDROID one is not it. Both are set here on purpose: a
        // single-credential implementation passes an ios-only test and sends
        // Android tokens an APNs credential, which fails at the carrier rather
        // than at the code.
        $this->assertNotSame(
            'CR'.str_repeat('2', 32),
            $grant['push_credential_sid'] ?? null,
        );
    }

    public function test_an_android_token_carries_its_own_credential(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, [
            'push_credential_ios_sid' => 'CR'.str_repeat('1', 32),
            'push_credential_android_sid' => 'CR'.str_repeat('2', 32),
        ]);

        $this->assertSame(
            'CR'.str_repeat('2', 32),
            $this->voiceGrantOf($connection, 'android')['push_credential_sid'] ?? null,
        );
    }

    /**
     * The browser gets no push credential, and that is correct.
     *
     * A desk browser holds its own signalling socket open, so there is nothing
     * to wake. Attaching a mobile credential to a web token would name a
     * device that is not there.
     */
    public function test_a_browser_token_names_no_push_credential(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, [
            'push_credential_ios_sid' => 'CR'.str_repeat('1', 32),
        ]);

        $grant = $this->voiceGrantOf($connection, null);

        $this->assertArrayNotHasKey('push_credential_sid', $grant);

        // And the grant is otherwise intact — this is the positive control, so
        // "no push credential" cannot pass by the grant being empty.
        $this->assertTrue(
            (bool) ($grant['incoming']['allow'] ?? false),
            'the voice grant lost incoming as well, so the absence above '
            .'proves nothing',
        );
    }

    /**
     * A company with no credential yet still gets a working token.
     *
     * Until Jose supplies an APNs key there is nothing to name, and refusing
     * would take the desk phone down to fix the mobile one.
     */
    public function test_a_connection_without_a_credential_still_mints_a_token(): void
    {
        $t = $this->provisionTenant();
        $grant = $this->voiceGrantOf($this->connection($t), 'ios');

        $this->assertArrayNotHasKey('push_credential_sid', $grant);
        $this->assertTrue((bool) ($grant['incoming']['allow'] ?? false));
    }
}
