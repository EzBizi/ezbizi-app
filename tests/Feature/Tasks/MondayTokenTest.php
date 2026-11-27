<?php

namespace Tests\Feature\Tasks;

use App\Models\SocialConnection;
use App\Support\Tasks\Monday\MondayRefused;
use App\Support\Tasks\Monday\MondayToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Monday's New OAuth Flow (desk, 2026-11-12): access tokens expire, refresh
 * tokens are single-use and rotate. Refreshed under 5 minutes before expiry,
 * by one caller at a time, stored as a pair; invalid_grant breaks the
 * connection ("Reconnect Monday") with no retry.
 */
class MondayTokenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TOKEN_URL = 'https://auth.monday.com/oauth_ms/oauth/token';

    private array $tenant;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.monday.client_id' => 'cid', 'services.monday.client_secret' => 'secret']);
        $this->tenant = $this->provisionTenant('Alpha');
        $this->connection = $this->connected(now()->addMinutes(2));
    }

    private function connected($expiresAt, string $access = 'A1', string $refresh = 'R1'): SocialConnection
    {
        return $this->inTenant($this->tenant['org'], function () use ($expiresAt, $access, $refresh) {
            $c = SocialConnection::query()->where('provider', 'monday')->first() ?? new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->tenant['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group',
            ]);
            $c->connect(['access_token' => $access, 'refresh_token' => $refresh, 'scopes' => [], 'expires_at' => $expiresAt->toIso8601String()]);
            $c->save();

            return $c;
        });
    }

    private function token(?SocialConnection $c = null): string
    {
        return app(MondayToken::class)->for($c ?? $this->connection);
    }

    private function fresh(): SocialConnection
    {
        return $this->inTenant($this->tenant['org'], fn () => SocialConnection::query()->findOrFail($this->connection->id));
    }

    public function test_a_token_about_to_run_out_is_rotated_and_the_new_pair_stored_together(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);

        $this->assertSame('A2', $this->token());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN_URL && $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === 'R1' && $r['client_id'] === 'cid' && $r['client_secret'] === 'secret' && $r->isJson());
        $row = $this->fresh();
        $this->assertSame('A2', $row->credentials['access_token']);
        $this->assertSame('R2', $row->credentials['refresh_token'], 'the spent refresh token is replaced, never kept');
        $this->assertTrue(now()->addMinutes(59)->lessThan($row->credentials['expires_at']));

        // Fresh now: the next caller uses it and spends nothing.
        $this->assertSame('A2', $this->token($row));
        Http::assertSentCount(1);
    }

    public function test_a_token_with_time_left_is_not_refreshed(): void
    {
        $this->connection = $this->connected(now()->addHour());
        Http::fake();

        $this->assertSame('A1', $this->token());
        Http::assertNothingSent();
    }

    /** Two runs holding the same old pair: only the first may spend it; the second takes what it stored. */
    public function test_one_refresher_at_a_time_and_a_waiting_run_uses_the_stored_pair(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'A9', 'refresh_token' => 'R9', 'expires_in' => 3600])]);
        $stale = $this->connection; // what the second run loaded: R1, about to expire

        // The first run holds the lock and rotates meanwhile.
        $lock = Cache::lock('monday-token:'.$this->connection->id, 60);
        $this->assertTrue($lock->get());
        $this->connected(now()->addHour(), 'A2', 'R2');

        // While it holds the lock, a second run does not refresh: it waits, then gives up this time.
        $token = app(MondayToken::class);
        $token->waitSeconds = 1;
        try {
            $token->for($stale);
            $this->fail('a run must not refresh while another holds the lock');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(MondayRefused::class, $e);
        }
        Http::assertNothingSent();

        // Once released, the waiting run reads the row again and uses the pair already stored.
        $lock->release();
        $this->assertSame('A2', $token->for($stale));
        Http::assertNothingSent();
        $this->assertSame('R2', $this->fresh()->credentials['refresh_token']);
    }

    public function test_invalid_grant_breaks_the_connection_once_with_reconnect_monday_and_no_retry(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh token reused'], 400)]);

        try {
            $this->token();
            $this->fail('invalid_grant must refuse');
        } catch (MondayRefused $e) {
            $this->assertSame(MondayRefused::RECONNECT, $e->getMessage());
        }
        $row = $this->fresh();
        $this->assertSame(SocialConnection::STATUS_BROKEN, $row->status);
        $this->assertStringContainsString('Reconnect Monday', $row->status_reason);
        Http::assertSentCount(1);

        // Broken stays broken: nothing more is spent until somebody reconnects.
        $this->expectException(MondayRefused::class);
        try {
            $this->token($row);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_the_poll_skips_a_broken_connection_and_says_so_on_the_card(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->artisan('ezbizi:monday-sync')->assertFailed();
        $this->assertSame(SocialConnection::STATUS_BROKEN, $this->fresh()->status);
        $this->assertSame(MondayRefused::RECONNECT, $this->fresh()->status_reason);

        $this->artisan('ezbizi:monday-sync')->assertSuccessful(); // a broken connection is not polled
        Http::assertSentCount(1);
    }
}
