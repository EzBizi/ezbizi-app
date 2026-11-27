<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Bug review 2026-11-09, finding 2: Instagram and Threads tokens last 60 days
 * and were never renewed, so the A1 Chimney Instagram would have died on
 * 2026-12-11. Renewed daily once fewer than 15 days remain.
 */
class RenewMetaLoginTokensTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_token_close_to_expiry_is_renewed_and_its_new_expiry_stored(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ig = $this->connection($t, 'instagram_professional', 'IGOLD', now()->addDays(10));
        $th = $this->connection($t, 'threads', 'THOLD', now()->addDays(3));
        Http::fake([
            'graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'IGNEW', 'expires_in' => 5184000]),
            'graph.threads.net/refresh_access_token*' => Http::response(['access_token' => 'THNEW', 'expires_in' => 5184000]),
        ]);

        $this->artisan('ezbizi:renew-meta-login-tokens')->assertSuccessful();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://graph.instagram.com/refresh_access_token')
            && $r['grant_type'] === 'ig_refresh_token' && $r['access_token'] === 'IGOLD');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://graph.threads.net/refresh_access_token')
            && $r['grant_type'] === 'th_refresh_token' && $r['access_token'] === 'THOLD');

        foreach ([[$ig, 'IGNEW'], [$th, 'THNEW']] as [$row, $token]) {
            $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->id));
            $this->assertSame($token, $saved->credentials['access_token']);
            $this->assertTrue(now()->addDays(59)->lt($saved->credentials['expires_at']));
        }
    }

    public function test_a_token_with_plenty_of_time_left_is_not_touched(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'instagram_professional', 'IGOLD', now()->addDays(40));
        Http::fake();

        $this->artisan('ezbizi:renew-meta-login-tokens')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function connection(array $t, string $provider, string $token, $expires): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $token, $expires) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => $provider, 'remote_id' => $provider.'-1', 'display_name' => 'A1 Chimney',
            ]);
            $row->connect(['access_token' => $token, 'expires_at' => $expires->toIso8601String()]);
            $row->save();

            return $row;
        });
    }
}
