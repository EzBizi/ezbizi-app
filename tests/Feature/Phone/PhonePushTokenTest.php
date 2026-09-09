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

    /**
     * The push credential is created on the connection's OWN account.
     *
     * ── The defect ───────────────────────────────────────────────────
     *
     * `notify.twilio.com/v1/Credentials` carries no account segment, unlike
     * `api.twilio.com/2010-04-01/Accounts/{sid}/…`. So the owning account is
     * decided by WHO AUTHENTICATES, and the account SID passed alongside is
     * ignored without a word.
     *
     * The first version of this used the platform helper — parent auth, child
     * SID — with a comment claiming it created the credential against the
     * subaccount. Run against production it created three credentials on the
     * PARENT, reported three successes with real CR SIDs, and filled three
     * columns with SIDs no subaccount token can use. Nothing errored.
     *
     * Asserted on the CLIENT'S IDENTITY rather than on a response, because the
     * response was indistinguishable from success and that is the whole story.
     */
    public function test_a_push_credential_is_created_as_the_connections_own_account(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, ['account_sid' => 'AC'.str_repeat('9', 32)]);
        $this->inTenant($t['org'], fn () => $connection
            ->forceFill(['auth_token' => 'this-accounts-own-token'])->save());

        $client = $this->inTenant($t['org'], fn () => $this->clientFor($connection));

        $this->assertSame(
            'AC'.str_repeat('9', 32),
            $client->getUsername(),
            'the credential would be created by whoever this authenticates as, '
            .'and it is not this connection',
        );
        $this->assertSame('AC'.str_repeat('9', 32), $client->getAccountSid());

        // The control: the platform account is configured and is NOT what came
        // back. Without this the assertion passes for a client built from a
        // config that happens to be empty in tests.
        $this->assertNotSame(
            (string) config('services.twilio.platform_account_sid'),
            $client->getUsername(),
            'this is the parent account, which is where the wrong credentials '
            .'were created',
        );
    }

    /** With no auth token it falls back to the account's own API key. */
    public function test_without_an_auth_token_the_accounts_api_key_is_used(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, ['account_sid' => 'AC'.str_repeat('8', 32)]);
        $this->inTenant($t['org'], fn () => $connection
            ->forceFill(['auth_token' => null])->save());

        $client = $this->inTenant($t['org'], fn () => $this->clientFor($connection));

        $this->assertSame('SK'.str_repeat('a', 32), $client->getUsername());

        // Still the connection's account, which is the part that decides where
        // the credential lands.
        $this->assertSame('AC'.str_repeat('8', 32), $client->getAccountSid());
    }

    /** A connection with nothing of its own is refused, not guessed at. */
    public function test_a_connection_with_no_credentials_of_its_own_is_refused(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, ['api_key_sid' => null]);
        $this->inTenant($t['org'], fn () => $connection
            ->forceFill(['auth_token' => '', 'credentials' => ''])->save());

        $this->expectException(\RuntimeException::class);
        $this->inTenant($t['org'], fn () => $this->clientFor($connection));
    }

    /**
     * A credential Twilio put on another account is refused, not stored.
     *
     * ── Why this guard exists at all ─────────────────────────────────
     *
     * When this shipped wrong, the create RESPONSE carried
     * `account_sid = <the parent>` on all three credentials. The answer was in
     * the payload and the code returned the SID without reading it, so the
     * command printed three successes and three columns were filled with
     * credentials no subaccount token can use.
     *
     * The check costs nothing — the field is already there — and it turns the
     * whole class of mistake from silent into loud. Tested on its own because
     * the guard is the part that was missing; the request was always fine.
     */
    public function test_a_credential_on_the_wrong_account_is_refused(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, ['account_sid' => 'AC'.str_repeat('7', 32)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not been stored');

        $this->ownerCheck($connection, 'AC'.str_repeat('0', 32));
    }

    /** And the right account passes, so the guard is not simply always on. */
    public function test_a_credential_on_the_right_account_is_accepted(): void
    {
        $t = $this->provisionTenant();
        $sid = 'AC'.str_repeat('6', 32);
        $connection = $this->connection($t, ['account_sid' => $sid]);

        $this->ownerCheck($connection, $sid);

        $this->addToAssertionCount(1);
    }

    /**
     * Twilio saying nothing about the owner is not the same as saying yes.
     *
     * An absent `account_sid` would compare equal to nothing and, on a
     * connection whose own SID were somehow empty, would pass. Named in the
     * message rather than guessed at.
     */
    public function test_a_response_naming_no_account_is_refused(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, ['account_sid' => 'AC'.str_repeat('5', 32)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(none reported)');

        $this->ownerCheck($connection, '');
    }

    private function ownerCheck(PhoneConnection $connection, string $ownerAccountSid): void
    {
        $method = new \ReflectionMethod(HttpTwilioGateway::class, 'assertOwnedBy');
        $method->setAccessible(true);
        $method->invoke(new HttpTwilioGateway, $ownerAccountSid, $connection);
    }

    /** The client `createPushCredential` would use, without a network call. */
    private function clientFor(PhoneConnection $connection): \Twilio\Rest\Client
    {
        $method = new \ReflectionMethod(HttpTwilioGateway::class, 'pushCredentialClient');
        $method->setAccessible(true);

        return $method->invoke(new HttpTwilioGateway, $connection->fresh());
    }

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
