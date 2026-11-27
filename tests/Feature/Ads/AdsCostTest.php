<?php

namespace Tests\Feature\Ads;

use App\Models\SocialConnection;
use App\Services\Ads\AdsCost;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports, 2026-10-12: a branch's Google ad cost as "LS Ads" and "PPC", from cache after the first read. */
class AdsCostTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function account(array $t, string $remote, ?string $franchiseId, bool $manager = false): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $remote, $franchiseId, $manager) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => $remote,
                'display_name' => 'A1 Chimney - '.$remote,
            ]);
            $row->forceFill(['leads_franchise_id' => $franchiseId, 'is_manager' => $manager]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    /** Desk audit, 2026-11-12: an account connected from inside a branch is that branch's, with no mapping. */
    public function test_an_account_connected_from_a_branch_counts_for_it_and_not_as_unmapped(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->account($t, '4444444444', null);
        $this->inTenant($t['org'], fn () => $row->forceFill(['scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $t['franchise']->id])->save());
        config(['services.google_ads.client_id' => 'c', 'services.google_ads.client_secret' => 's', 'services.google_ads.developer_token' => 'd']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response(['results' => []], 200),
        ]);
        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-09-30');

        $this->assertSame(1, app(AdsCost::class)->forBranch($t['franchise'], $from, $to)['accounts']);
        $this->assertSame(0, app(AdsCost::class)->unmapped((string) $t['org']->id, null, $from, $to)['accounts']);
    }

    public function test_a_branch_sums_its_accounts_split_lsa_and_ppc_and_asks_google_once(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $branch = $t['franchise'];
        $this->account($t, '1111111111', $branch->id);
        $this->account($t, '2222222222', null);                 // another branch's
        $this->account($t, '3333333333', $branch->id, true);    // a manager: no spend

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response(['results' => [
                ['customer' => ['currencyCode' => 'USD'], 'campaign' => ['advertisingChannelType' => 'LOCAL_SERVICES'],
                    'metrics' => ['costMicros' => '123450000', 'clicks' => '10', 'conversions' => 4]],
                ['customer' => ['currencyCode' => 'USD'], 'campaign' => ['advertisingChannelType' => 'SEARCH'],
                    'metrics' => ['costMicros' => '50000000', 'clicks' => '7', 'conversions' => 1.5]],
                ['customer' => ['currencyCode' => 'USD'], 'campaign' => ['advertisingChannelType' => 'PERFORMANCE_MAX'],
                    'metrics' => ['costMicros' => '1000000', 'clicks' => '1', 'conversions' => 0]],
            ]], 200),
        ]);

        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-09-30');
        $cost = app(AdsCost::class)->forBranch($branch, $from, $to);

        $this->assertSame(1, $cost['accounts']);
        $this->assertSame(['cost_minor' => 12345, 'clicks' => 10, 'conversions' => 4.0], $cost['lsa']);
        $this->assertSame(['cost_minor' => 5100, 'clicks' => 8, 'conversions' => 1.5], $cost['ppc']);
        $this->assertSame([], $cost['refused']);

        // Asked at campaign-channel level, never below.
        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'googleads') || (str_contains($r['query'], 'FROM campaign ') && ! str_contains($r['query'], 'ad_group')));

        // The second read is the cache's.
        $sent = count(Http::recorded());
        app(AdsCost::class)->forBranch($branch, $from, $to);
        $this->assertCount($sent, Http::recorded());
    }
}
