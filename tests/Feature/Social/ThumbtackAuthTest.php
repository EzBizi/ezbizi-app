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

    /**
     * The credentials go in the Authorization header, never in the body.
     *
     * Both are legal OAuth 2.0, and Thumbtack's client accepts only Basic. It
     * refuses the other with `invalid_client`, whose stock wording is "unknown
     * client, no client authentication included, or unsupported authentication
     * method" - indistinguishable from a wrong secret. Every live connect died
     * here on 2026-09-08 and the screen told the Pro their account was fine
     * while EzBizi logged nothing at all.
     *
     * Forty-one Thumbtack tests passed through that bug, because every one of
     * them faked the response and none asserted how the request authenticated.
     */
    public function test_the_token_call_authenticates_with_basic_and_not_body_credentials(): void
    {
        Http::fake(['*/oauth2/token' => Http::response([
            'access_token'  => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in'    => 3600,
        ])]);

        app(ThumbtackOAuth::class)->exchange('an-auth-code');

        Http::assertSent(function ($request) {
            // Read defensively: with the header absent entirely - which is
            // exactly the bug - indexing it would ERROR rather than fail, and
            // an unreadable failure is most of the way back to no test at all.
            $sent = $request->header('Authorization')[0] ?? '(no Authorization header)';

            return $sent === 'Basic '.base64_encode('a-client:a-secret')
                // And NOT repeated in the body: Hydra treats credentials in
                // two places as a malformed request, so sending both would
                // trade one refusal for another.
                && ! isset($request->data()['client_secret'])
                && ! isset($request->data()['client_id']);
        });
    }

    /** Refresh authenticates the same way; it is the same client and the same server. */
    public function test_refreshing_also_authenticates_with_basic(): void
    {
        Http::fake(['*/oauth2/token' => Http::response([
            'access_token' => 'newer-access',
            'expires_in'   => 3600,
        ])]);

        app(ThumbtackOAuth::class)->refresh('a-refresh-token');

        Http::assertSent(fn ($request) => ($request->header('Authorization')[0] ?? null)
                === 'Basic '.base64_encode('a-client:a-secret')
            && ! isset($request->data()['client_secret']));
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

    /**
     * One login, twelve businesses, ONE token pair — and a rotation that must
     * reach all of them.
     *
     * identify() returns an account per BUSINESS from a single OAuth grant, so
     * a connect writes N rows all carrying the same tokens. Thumbtack rotates
     * the refresh token on every exchange and kills the old one.
     *
     * Saving the rotated pair to one row left the others holding a dead value.
     * Measured in production on 2026-09-09, an hour after Jose connected:
     * eleven rows on one fingerprint, one rotated, seventeen invalid_grant
     * refusals, then the whole family revoked — reuse detection reading eleven
     * replays of a spent token as a stolen one. Every connection dead, and only
     * a reconnect could fix it.
     */
    public function test_one_grant_shared_by_many_rows_rotates_for_all_of_them(): void
    {
        $t = $this->provisionTenant('A1-Group');

        // Three businesses, one login: identical tokens, as a connect writes them.
        $rows = collect(['biz-1', 'biz-2', 'biz-3'])->map(
            fn (string $id) => $this->storedConnection($t, $id, 'shared-access', 'shared-refresh'),
        );

        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::response([
                'access_token'  => 'rotated-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in'    => 3600,
            ]),
            // The first call 401s, forcing the refresh; the retry succeeds.
            'api.thumbtack.com/*' => Http::sequence()
                ->push(['title' => 'Unauthorized'], 401)
                ->push(['data' => []]),
        ]);

        $this->inTenant($t['org'], function () use ($rows) {
            (new ThumbtackClient($rows->first()))->get('/v4/businesses');
        });

        // ALL of them, not just the one that did the exchange. The other two
        // are what died in production.
        $this->inTenant($t['org'], function () {
            $stored = SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)
                ->get()
                ->map(fn ($r) => $r->getAttribute('credentials')['refresh_token'] ?? null)
                ->unique();

            $this->assertSame(['rotated-refresh'], $stored->values()->all());
        });
    }

    /**
     * A refused grant marks every row that held it broken, so Leads says
     * "needs reconnecting" (2026-09-30: a lead arrived with an empty thread and
     * the rows still said connected). A transient failure leaves them alone.
     */
    public function test_a_dead_grant_marks_every_row_that_held_it_broken(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $rows = collect(['biz-1', 'biz-2'])->map(
            fn (string $id) => $this->storedConnection($t, $id, 'shared-access', 'shared-refresh'),
        );

        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::sequence()
                ->push(['error' => 'server_error'], 503)
                ->push(['error' => 'invalid_grant', 'error_description' => 'The refresh token is malformed or not valid.'], 400),
            'api.thumbtack.com/*' => Http::response(['title' => 'Unauthorized'], 401),
        ]);

        $statuses = function () use ($t) {
            return $this->inTenant($t['org'], fn () => SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)->pluck('status')->unique()->values()->all());
        };

        foreach ([[SocialConnection::STATUS_CONNECTED], [SocialConnection::STATUS_BROKEN]] as $expected) {
            try {
                $this->inTenant($t['org'], fn () => (new ThumbtackClient($rows->first()))->get('/v4/businesses'));
            } catch (\Throwable) {
            }
            $this->assertSame($expected, $statuses());
        }
    }

    /**
     * The grant is exchanged daily even with no lead to fetch, once per
     * login, and the rotation reaches every row (2026-09-30: a grant idle for
     * 15 days was refused).
     */
    public function test_the_keep_alive_rotates_an_idle_grant_once_for_all_its_rows(): void
    {
        $t = $this->provisionTenant('A1-Group');
        collect(['biz-1', 'biz-2'])->each(
            fn (string $id) => $this->storedConnection($t, $id, 'shared-access', 'shared-refresh'),
        );

        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::response([
                'access_token' => 'rotated-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $this->artisan('ezbizi:thumbtack-keep-alive')->assertSuccessful();

        Http::assertSentCount(1);

        // Audit 11-27: a second login in the same organization is its own grant, kept alive too.
        $this->storedConnection($t, 'biz-3', 'other-access', 'other-refresh');
        $this->artisan('ezbizi:thumbtack-keep-alive')->assertSuccessful();
        Http::assertSentCount(3);
        $this->inTenant($t['org'], function () {
            $stored = SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)
                ->get()
                ->map(fn ($r) => $r->getAttribute('credentials')['refresh_token'] ?? null)
                ->unique();

            $this->assertSame(['rotated-refresh'], $stored->values()->all());
        });
    }

    private function storedConnection(array $t, string $remoteId, string $access, string $refresh): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $remoteId, $access, $refresh) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => $remoteId,
                'display_name'    => 'A-1 Chimney',
            ]);
            $row->connect(['access_token' => $access, 'refresh_token' => $refresh]);
            $row->save();

            return $row;
        });
    }
}
