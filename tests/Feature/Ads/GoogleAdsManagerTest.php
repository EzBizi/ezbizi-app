<?php

namespace Tests\Feature\Ads;

use App\Models\AuditEvent;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Marketing › Ads › Google (Jose, 2026-11-09): campaigns listed with what can
 * be changed, and status, daily budget and bid target changed at Google,
 * audited, on ads_manager.manage alone.
 */
class GoogleAdsManagerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connected(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    /** @param  list<array>  $bodies  googleads answers, in order */
    private function fakeGoogle(array $bodies): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->push($body, 200);
        }
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => $sequence,
        ]);
        Http::preventStrayRequests();
    }

    private function campaign(string $id, array $extra = []): array
    {
        return ['customer' => ['currencyCode' => 'USD'], 'campaign' => ['id' => $id, 'name' => 'C'.$id, 'status' => 'ENABLED',
            'advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => 'TARGET_CPA', 'targetCpa' => ['targetCpaMicros' => '45000000']] + $extra,
            'campaignBudget' => ['resourceName' => 'customers/4941850746/campaignBudgets/9', 'amountMicros' => '30000000', 'referenceCount' => '1']];
    }

    public function test_campaigns_list_with_budget_target_and_metrics(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->connected($t);
        $this->fakeGoogle([
            ['results' => [$this->campaign('11'), ['campaign' => ['id' => '12', 'name' => 'LSA', 'status' => 'PAUSED', 'advertisingChannelType' => 'LOCAL_SERVICES']]]],
            ['results' => [['campaign' => ['id' => '11'], 'metrics' => ['costMicros' => '90000000', 'clicks' => '30', 'conversions' => 3]]]],
        ]);

        $data = $this->signedInAs($this->userWithRole($t, 'Marketing', ['ads_manager.view']))
            ->getJson('/api/v1/ads-manager/google/campaigns?connection_id='.$row->getKey().'&from=2026-11-01&to=2026-11-08')
            ->assertOk()->json('data');

        $this->assertSame('USD', $data['currency']);
        $this->assertSame(['11', 30, 'cpa', 45, 90, 3, 30], [$data['campaigns'][0]['id'], $data['campaigns'][0]['daily_budget'], $data['campaigns'][0]['target_kind'],
            $data['campaigns'][0]['target'], $data['campaigns'][0]['spend'], $data['campaigns'][0]['conversions'], $data['campaigns'][0]['cost_per_conversion']]);
        $this->assertSame(['LOCAL_SERVICES', 'PAUSED', 0], [$data['campaigns'][1]['type'], $data['campaigns'][1]['status'], $data['campaigns'][1]['clicks']]);
    }

    public function test_budget_and_target_send_only_their_field_and_are_audited(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->connected($t);
        $this->fakeGoogle([
            ['results' => [$this->campaign('11')]], ['results' => [[]]],   // budget: read, mutate
            ['results' => [$this->campaign('11')]], ['results' => [[]]],   // target: read, mutate
            ['results' => [[]]],                                           // status: mutate
        ]);
        $as = $this->signedInAs($this->userWithRole($t, 'Marketing', ['ads_manager.view', 'ads_manager.manage']));
        $base = ['connection_id' => $row->getKey(), 'campaign_id' => '11'];

        $as->postJson('/api/v1/ads-manager/google/budget', $base + ['daily_budget' => 42.5])->assertOk();
        $as->postJson('/api/v1/ads-manager/google/target', $base + ['target' => 50])->assertOk();
        $as->postJson('/api/v1/ads-manager/google/status', $base + ['status' => 'PAUSED'])->assertOk();

        $mutates = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), ':mutate'))->values();
        $this->assertSame([
            ['customers/4941850746/campaignBudgets:mutate', 'amountMicros', ['resourceName' => 'customers/4941850746/campaignBudgets/9', 'amountMicros' => '42500000']],
            ['customers/4941850746/campaigns:mutate', 'targetCpa.targetCpaMicros', ['resourceName' => 'customers/4941850746/campaigns/11', 'targetCpa' => ['targetCpaMicros' => '50000000']]],
            ['customers/4941850746/campaigns:mutate', 'status', ['resourceName' => 'customers/4941850746/campaigns/11', 'status' => 'PAUSED']],
        ], $mutates->map(fn (Request $r) => [substr($r->url(), strpos($r->url(), 'customers/')), $r['operations'][0]['updateMask'], $r['operations'][0]['update']])->all());

        $this->inTenant($t['org'], fn () => $this->assertSame(
            ['google_ads.budget_changed', 'google_ads.campaign_status_changed', 'google_ads.target_changed'],
            AuditEvent::query()->where('action', 'like', 'google_ads.%')->pluck('action')->sort()->values()->all(),
        ));
    }

    public function test_a_shared_budget_is_refused_and_view_alone_changes_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->connected($t);
        $shared = $this->campaign('11');
        $shared['campaignBudget']['referenceCount'] = '3';
        $this->fakeGoogle([['results' => [$shared]]]);
        $base = ['connection_id' => $row->getKey(), 'campaign_id' => '11'];

        $this->signedInAs($this->userWithRole($t, 'Looker', ['ads_manager.view', 'ads_performance.manage']))
            ->postJson('/api/v1/ads-manager/google/budget', $base + ['daily_budget' => 10])->assertForbidden();
        $this->signedInAs($this->userWithRole($t, 'Marketing', ['ads_manager.view', 'ads_manager.manage']))
            ->postJson('/api/v1/ads-manager/google/budget', $base + ['daily_budget' => 10])
            ->assertUnprocessable()->assertJsonPath('message', 'This budget is shared with other campaigns; change it in Google Ads.');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), ':mutate'));
    }
}
