<?php

namespace Tests\Feature\Leads;

use App\Console\Commands\SyncLeads;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * When Google stops answering, the Leads screen says so.
 *
 * ── The four hours this is for ──────────────────────────────────
 *
 * 2026-09-16: Google refused every Local Services fetch from 09:25 to 13:25.
 * 2,033 refusals. Nothing on any screen said a word. Jose found out at 12:40
 * because he opened the Ads report for an unrelated reason, and by then four
 * hours of enquiries had not arrived.
 *
 * The list re-reads itself every twenty seconds as of 3626d392, which makes
 * this WORSE rather than better: a stalled sync now looks exactly like a quiet
 * afternoon — the same rows, refreshed, over and over, with a dispatcher
 * watching it happen.
 *
 * ── Why a cache key with a TTL ──────────────────────────────────
 *
 * The fact expires on its own. Every abandoned run rewrites it; the first run
 * that does not abandon simply stops renewing it. Nobody writes a "recovered"
 * path, so nobody can get the edge wrong — clearing a state is rarely the
 * inverse of entering it, and the cheapest way to be right about that is not
 * to have an exit at all.
 */
class AStalledSyncSaysSoOnTheScreenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t, string $customerId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customerId) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->getKey(),
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => $customerId,
                'display_name' => 'A1 Chimney - '.$customerId,
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function googleRefusesEverything(): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED'],
            ], 429),
        ]);
    }

    /** What the screen reads. Null means nothing to say. */
    private function delayedSince(array $t): ?string
    {
        return $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.sync_delayed_since');
    }

    public function test_the_screen_says_nothing_while_the_sync_is_healthy(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $this->assertNull(
            $this->delayedSince($t),
            'the control: a quiet afternoon must not be dressed as an outage',
        );
    }

    /**
     * THE ONE THIS EXISTS FOR. A stalled run reaches the screen.
     */
    public function test_a_run_abandoned_on_quota_reaches_the_screen(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        foreach (['1111111111', '2222222222', '3333333333', '4444444444'] as $id) {
            $this->connection($t, $id);
        }

        $this->googleRefusesEverything();

        $this->assertNull($this->delayedSince($t));

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $this->assertNotNull(
            $this->delayedSince($t),
            'Google refused every account and the screen a dispatcher watches said nothing',
        );
    }

    /**
     * AND IT CLEARS BY ITSELF, which is the half a "recovered" path would get
     * wrong. Expiring the key is the whole recovery mechanism.
     */
    public function test_it_clears_on_its_own_without_a_recovery_path(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        Cache::put(SyncLeads::STALLED_KEY, now()->toIso8601String(), now()->addMinutes(35));
        $this->assertNotNull($this->delayedSince($t));

        // What the TTL does when nothing renews it.
        Cache::forget(SyncLeads::STALLED_KEY);

        $this->assertNull(
            $this->delayedSince($t),
            'the banner must go when the stall does, without anybody clearing it',
        );
    }

    /**
     * A SINGLE ACCOUNT FAILING IS NOT AN OUTAGE.
     *
     * The brake needs three refusals in a row before it abandons a run, and
     * only an abandoned run says anything. Without this, one suspended account
     * — and A-1 holds accounts Google itself calls deactivated — would put
     * "enquiries are delayed" on the screen of a business whose enquiries are
     * arriving perfectly well.
     */
    public function test_one_refusing_account_does_not_claim_an_outage(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $this->connection($t, '1111111111');
        $this->connection($t, '2222222222');

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/v24/customers/1111111111/*' => Http::response([
                'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED'],
            ], 429),
            'googleads.googleapis.com/*' => Http::response(['results' => []], 200),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $this->assertNull(
            $this->delayedSince($t),
            'one account refusing is not the developer token being spent',
        );
    }
}
