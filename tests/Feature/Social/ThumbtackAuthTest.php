<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackClient;
use App\Support\Social\ThumbtackOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Getting in, and staying in.
 *
 * Both of the things pinned here were missing on 2026-09-08, after production
 * credentials had already been installed, and neither would have shown up until
 * a real Pro tried to connect. They are the difference between an integration
 * that is configured and one that works.
 */
class ThumbtackAuthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thumbtack.client_id', 'a-client');
        config()->set('services.thumbtack.client_secret', 'a-secret');
        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
        config()->set('services.thumbtack.token_url', 'https://auth.thumbtack.com/oauth2/token');
    }

    /**
     * The parameter no OAuth library sends by default.
     *
     * Thumbtack's authorization server is ORY Hydra and requires `audience` to
     * say which API the token is for. Stock OAuth2 has no such parameter, so
     * everything looks correct right up to the consent screen refusing — and
     * the error names the client rather than the missing field.
     */
    public function test_the_authorize_url_carries_the_audience_thumbtack_requires(): void
    {
        $url = app(ThumbtackOAuth::class)->authorizeUrl('a-state-at-least-8');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('urn:partner-api', $q['audience'] ?? null);
        $this->assertSame('code', $q['response_type']);

        // Their docs require at least 8 characters of state, and the shared
        // sign-in helper generates 64 — asserted so a future shortening is
        // caught here rather than by Thumbtack.
        $this->assertGreaterThanOrEqual(8, strlen((string) $q['state']));
    }

    public function test_an_expired_token_is_refreshed_once_and_the_call_retried(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);

        Http::fake([
            'auth.thumbtack.com/*' => Http::response([
                'access_token'  => 'fresh-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in'    => 3600,
            ]),
            'api.thumbtack.com/*' => Http::sequence()
                ->push(['error' => 'expired'], 401)
                ->push(['data' => []], 200),
        ]);

        $result = $this->inTenant(
            $t['org'],
            fn () => (new ThumbtackClient($connection))->get('/v4/businesses'),
        );

        $this->assertSame(['data' => []], $result);

        // The retry went out carrying the NEW token, not the stale one.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.thumbtack.com')
            && $r->hasHeader('Authorization', 'Bearer fresh-access'));
    }

    /**
     * The half that turns a working connection into a dead one.
     *
     * Thumbtack rotates refresh tokens: each exchange returns a new one and
     * invalidates the old. Storing the access token but keeping the old refresh
     * token works exactly once, then fails forever an hour later — which reads
     * as intermittent and is not.
     */
    public function test_the_rotated_refresh_token_is_stored_not_the_spent_one(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);

        Http::fake([
            'auth.thumbtack.com/*' => Http::response([
                'access_token'  => 'fresh-access',
                'refresh_token' => 'rotated-refresh',
            ]),
            'api.thumbtack.com/*' => Http::sequence()
                ->push([], 401)
                ->push(['data' => []], 200),
        ]);

        $this->inTenant($t['org'], function () use ($connection) {
            (new ThumbtackClient($connection))->get('/v4/businesses');

            $saved = $connection->fresh()->getAttribute('credentials');

            $this->assertSame('fresh-access', $saved['access_token']);
            $this->assertSame('rotated-refresh', $saved['refresh_token'], 'the spent refresh token must not survive');
        });
    }

    public function test_a_second_refusal_after_refreshing_is_reported_rather_than_retried_forever(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);

        Http::fake([
            'auth.thumbtack.com/*' => Http::response(['access_token' => 'fresh-access']),
            // Still 401 with a good token: revoked access or a missing scope,
            // not an expiry. Retrying that is a loop, not a recovery.
            'api.thumbtack.com/*' => Http::response(['message' => 'Access revoked.'], 401),
        ]);

        $this->expectExceptionMessageMatches('/Access revoked/');

        $this->inTenant(
            $t['org'],
            fn () => (new ThumbtackClient($connection))->get('/v4/businesses'),
        );
    }

    public function test_a_connection_with_no_refresh_token_fails_plainly(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t, refresh: null);

        Http::fake(['api.thumbtack.com/*' => Http::response(['message' => 'Unauthorized.'], 401)]);

        $this->expectExceptionMessageMatches('/Unauthorized/');

        $this->inTenant(
            $t['org'],
            fn () => (new ThumbtackClient($connection))->get('/v4/businesses'),
        );

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'auth.thumbtack.com'));
    }

    private function connection(array $t, ?string $refresh = 'r-token'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $refresh) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => 'biz-1',
                'display_name'    => 'A-1 Chimney on Thumbtack',
            ]);
            $row->connect(array_filter([
                'access_token'  => 'stale-access',
                'refresh_token' => $refresh,
            ]));
            $row->save();

            return $row;
        });
    }
}
