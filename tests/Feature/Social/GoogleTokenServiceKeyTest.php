<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A wrong service key must not mark a healthy connection broken.
 *
 * `forConnection($connection, $service)` takes a `config/services.php` KEY —
 * `google_ads`, `business_profile` — not a human label. Pass anything else and
 * `config("services.{$service}.client_id")` is null, Google is handed an empty
 * client, the refresh fails, and the failure path writes STATUS_BROKEN to the
 * row before throwing.
 *
 * So a typo does not fail: it corrupts a production row and tells a customer to
 * reconnect an account that was never broken. This is not hypothetical — it
 * happened to A-1 Chimney's live Google Ads connection while diagnosing the
 * Basic Access approval, from one wrong argument in a one-off command.
 *
 * All four real call sites pass the right key. The point is that the fifth one
 * cannot quietly do this.
 */
class GoogleTokenServiceKeyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $tenant): SocialConnection
    {
        // `credentials` is not fillable — it is encrypted and set through
        // connect(), which is the only way a real grant ever arrives.
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $row = SocialConnection::create([
                'organization_id' => $tenant['org']->id,
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $tenant['org']->id,
                'provider'        => 'google_ads',
                'remote_id'       => '7061094046',
                'display_name'    => '706-109-4046',
            ]);

            $row->connect(['refresh_token' => 'rt-live-and-perfectly-good']);
            $row->save();

            return $row;
        });
    }

    public function test_an_unknown_service_key_is_refused_without_touching_the_connection(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t);

        // If the guard is missing this reaches Google with an empty client id.
        Http::fake(['oauth2.googleapis.com/*' => Http::response(
            ['error' => 'invalid_client'],
            401,
        )]);

        $this->inTenant($t['org'], function () use ($row) {
            try {
                GoogleToken::forConnection($row, 'Google Ads');
                $this->fail('An unknown service key must be refused.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Google Ads', $e->getMessage());
            }
        });

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        $this->assertNotSame(
            SocialConnection::STATUS_BROKEN,
            $fresh->status,
            'A key EzBizi got wrong is not evidence the customer\'s grant is bad.',
        );
        $this->assertNull($fresh->status_reason);

        Http::assertNothingSent();
    }

    public function test_a_real_refusal_from_google_still_marks_the_connection_broken(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t);

        // The suite carries no real OAuth client, and the new guard correctly
        // refuses to send anything without one. Configure a fake so this test
        // reaches the path it is actually about — Google saying no.
        config([
            'services.google_ads.client_id'     => 'test-client-id',
            'services.google_ads.client_secret' => 'test-client-secret',
        ]);

        Http::fake(['oauth2.googleapis.com/*' => Http::response(
            ['error' => 'invalid_grant'],
            400,
        )]);

        $this->inTenant($t['org'], function () use ($row) {
            try {
                GoogleToken::forConnection($row, 'google_ads');
                $this->fail('Google refusing the refresh must throw.');
            } catch (RuntimeException) {
                // expected
            }
        });

        // The guard must not have softened the case it was NOT written for: a
        // grant Google will genuinely not renew still has to be marked, or the
        // screen keeps offering a connection that cannot work.
        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        $this->assertSame(SocialConnection::STATUS_BROKEN, $fresh->status);
        $this->assertNotNull($fresh->status_reason);
    }
}
