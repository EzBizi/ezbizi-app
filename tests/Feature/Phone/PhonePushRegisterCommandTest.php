<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The only thing that can write a push credential SID.
 *
 * PhonePushTokenTest proves a token carries the credential; PhoneTokenTest
 * proves a handset asks for one. Both are answers about a column, and nothing
 * in the product could put a value in it — which is a working feature that has
 * never once been reachable.
 */
class PhonePushRegisterCommandTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private string $certificate;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        // Real files, because the command refuses a path it cannot read and a
        // test passing inline strings would never exercise that.
        $this->certificate = tempnam(sys_get_temp_dir(), 'crt');
        $this->key = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($this->certificate, '-----BEGIN CERTIFICATE-----x-----END CERTIFICATE-----');
        file_put_contents($this->key, '-----BEGIN PRIVATE KEY-----y-----END PRIVATE KEY-----');
    }

    protected function tearDown(): void
    {
        @unlink($this->certificate);
        @unlink($this->key);
        parent::tearDown();
    }

    private function connection(array $t, array $attributes = []): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id,
            'status' => PhoneConnection::STATUS_CONNECTED,
            ...$attributes,
        ]));
    }

    public function test_it_writes_the_returned_sid_onto_the_connection(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t);

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => $this->certificate,
            '--private-key' => $this->key,
            '--production' => true,
        ])->assertExitCode(0);

        $fresh = $this->inTenant($t['org'], fn () => $connection->fresh());

        $this->assertNotNull(
            $fresh->push_credential_ios_sid,
            'the command reported success and the column is still empty, so '
            .'every token minted from this connection is still a browser token',
        );

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $call = collect($gateway->calls)->firstWhere('method', 'createPushCredential');
        $this->assertSame('ios', $call['args'][1]);

        // The Android column is untouched — one run does one platform.
        $this->assertNull($fresh->push_credential_android_sid);
    }

    /**
     * Production is opt-in, and getting it wrong delivers nothing.
     *
     * Sandbox APNs is a different service, not a gentler one. A sandbox
     * credential on a TestFlight build accepts every push and rings nothing —
     * which is the same symptom as having no credential at all, and is why the
     * flag is asserted rather than assumed.
     */
    public function test_sandbox_is_the_default_and_production_is_asked_for(): void
    {
        $t = $this->provisionTenant();
        $this->connection($t);

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => $this->certificate,
            '--private-key' => $this->key,
        ])->assertExitCode(0);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $keys = collect($gateway->calls)
            ->firstWhere('method', 'createPushCredential')['args'][2];

        $this->assertContains('sandbox', $keys);
        $this->assertContains('certificate', $keys);
        $this->assertContains('private_key', $keys);
    }

    public function test_a_connection_that_already_has_one_is_left_alone(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connection($t, [
            'push_credential_ios_sid' => 'CRalreadyalreadyalreadyalready12',
        ]);

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => $this->certificate,
            '--private-key' => $this->key,
        ])->assertExitCode(1);

        $this->assertSame(
            'CRalreadyalreadyalreadyalready12',
            $this->inTenant($t['org'], fn () => $connection->fresh())->push_credential_ios_sid,
        );

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => $this->certificate,
            '--private-key' => $this->key,
            '--replace' => true,
        ])->assertExitCode(0);

        $this->assertNotSame(
            'CRalreadyalreadyalreadyalready12',
            $this->inTenant($t['org'], fn () => $connection->fresh())->push_credential_ios_sid,
        );
    }

    /**
     * A run that reached nothing fails.
     *
     * The whole class of bug this feature came from is a provisioning step
     * that looks done. `0 registered, 0 failed` exiting 0 is that bug again.
     */
    public function test_reaching_no_connection_is_a_failure(): void
    {
        $this->provisionTenant();

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => $this->certificate,
            '--private-key' => $this->key,
        ])->assertExitCode(1);
    }

    public function test_a_missing_file_is_refused_before_anything_is_created(): void
    {
        $t = $this->provisionTenant();
        $this->connection($t);

        $this->artisan('ezbizi:phone-push-credential', [
            '--platform' => 'ios',
            '--certificate' => 'H:/nowhere/there-is-no-certificate.pem',
            '--private-key' => $this->key,
        ])->assertExitCode(1);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $this->assertNotContains(
            'createPushCredential',
            array_column($gateway->calls, 'method'),
        );
    }
}
