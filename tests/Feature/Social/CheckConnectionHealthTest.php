<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-03: an Integrations card must never say "Connected" on a dead token. */
class CheckConnectionHealthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function connection(array $t, string $provider, string $token, array $extra = []): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $token, $extra) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => $provider, 'remote_id' => 'r-'.$token, 'display_name' => 'A-1 '.$token]);
            $c->connect(['access_token' => $token, 'refresh_token' => 'refresh-'.$token] + $extra);
            $c->save();
            DB::table('social_connections')->where('id', $c->id)->update(['last_reached_at' => null]);

            return $c;
        });
    }

    private function row(SocialConnection $c): object
    {
        return DB::table('social_connections')->where('id', $c->id)->first();
    }

    private function pinterest(): void
    {
        Http::fake(fn ($r) => match (true) {
            $r->header('Authorization') === ['Bearer live'] => Http::response(['username' => 'a1']),
            $r->header('Authorization') === ['Bearer dead'] => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401),
            $r->header('Authorization') === ['Bearer limited'] => Http::response(['code' => 8], 429),
            default => Http::response('down', 503),
        });
    }

    public function test_a_working_token_stamps_both_timestamps(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $live = $this->connection($t, SocialProvider::Pinterest->value, 'live');
        $this->pinterest();

        $this->artisan('ezbizi:check-connection-health')->assertSuccessful();

        $row = $this->row($live);
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->status);
        $this->assertNotNull($row->last_reached_at);
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_a_401_marks_the_connection_broken_with_a_sentence(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $dead = $this->connection($t, SocialProvider::Pinterest->value, 'dead');
        $this->pinterest();

        $this->artisan('ezbizi:check-connection-health')->assertSuccessful();

        $row = $this->row($dead);
        $this->assertSame(SocialConnection::STATUS_BROKEN, $row->status);
        $this->assertSame(ConnectionLiveness::sentence('Pinterest'), $row->status_reason);
        $this->assertNotNull($row->last_checked_at);
        $this->assertNull($row->last_reached_at);
    }

    public function test_a_429_or_a_5xx_leaves_it_connected_and_records_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $limited = $this->connection($t, SocialProvider::Pinterest->value, 'limited');
        $down = $this->connection($t, SocialProvider::Pinterest->value, 'down');
        $this->pinterest();

        $this->artisan('ezbizi:check-connection-health')->assertSuccessful();

        foreach ([$limited, $down] as $c) {
            $row = $this->row($c);
            $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->status);
            $this->assertNull($row->status_reason);
            $this->assertNull($row->last_checked_at);
            $this->assertNull($row->last_reached_at);
        }
        // Spaced, not burst: one pause between the two Pinterest calls.
        Sleep::assertSleptTimes(1);
    }

    public function test_google_invalid_grant_is_broken_and_a_google_429_is_not(): void
    {
        config(['services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'sec']);
        $t = $this->provisionTenant('A1-Group');
        $revoked = $this->connection($t, SocialProvider::GoogleBusinessProfile->value, 'revoked');
        $busy = $this->connection($t, SocialProvider::GoogleBusinessProfile->value, 'busy');
        $fine = $this->connection($t, SocialProvider::GoogleBusinessProfile->value, 'fine');

        Http::fake(fn ($r) => match ($r['refresh_token'] ?? null) {
            'refresh-revoked' => Http::response(['error' => 'invalid_grant'], 400),
            'refresh-busy' => Http::response(['error' => 'rate_limit_exceeded'], 429),
            default => Http::response(['access_token' => 'ya29', 'expires_in' => 3600]),
        });

        $this->artisan('ezbizi:check-connection-health')->assertSuccessful();

        $this->assertSame(SocialConnection::STATUS_BROKEN, $this->row($revoked)->status);
        $this->assertNotNull($this->row($revoked)->status_reason);
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $this->row($busy)->status);
        $this->assertNull($this->row($busy)->last_checked_at);
        $this->assertNotNull($this->row($fine)->last_checked_at);
    }

    public function test_the_connections_payload_carries_both_timestamps(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $live = $this->connection($t, SocialProvider::Pinterest->value, 'live');
        $this->pinterest();
        $this->artisan('ezbizi:check-connection-health')->assertSuccessful();

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections')->assertOk()->json('data'))
            ->firstWhere('id', $live->id);

        $this->assertNotNull($row['last_checked_at']);
        $this->assertNotNull($row['last_reached_at']);
    }
}
