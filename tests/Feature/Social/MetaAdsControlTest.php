<?php

namespace Tests\Feature\Social;

use App\Models\AuditEvent;
use App\Models\SocialConnection;
use App\Support\Social\SocialOAuthDrivers;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Meta campaigns read, paused and re-budgeted from EzBizi (Jose, 2026-11-04). */
class MetaAdsControlTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function account(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], fn () => tap(SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'provider' => SocialProvider::FacebookAds->value,
            'remote_id' => 'act_111',
            'display_name' => 'A1 Chimney',
        ]), fn ($c) => $c->forceFill(['credentials' => ['access_token' => 'user-token']])->save()));
    }

    private function manager(array $t)
    {
        return $this->userWithRole($t, 'Marketing', ['ads_performance.view', 'ads_performance.manage']);
    }

    public function test_both_facebook_connects_ask_for_ads_management(): void
    {
        foreach (['facebook', 'facebook-ads'] as $key) {
            $this->assertContains('ads_management', app(SocialOAuthDrivers::class)->byRouteKey($key)->scopes());
        }
    }

    public function test_the_tree_sums_each_ad_up_to_its_ad_set_and_campaign(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->account($t);
        Http::fake([
            'graph.facebook.com/*/act_111/insights*' => Http::response(['data' => [
                ['campaign_id' => '1', 'adset_id' => '11', 'ad_id' => '111', 'spend' => '30.00', 'impressions' => '1000', 'clicks' => '10', 'actions' => [['action_type' => 'lead', 'value' => '2']]],
                ['campaign_id' => '1', 'adset_id' => '11', 'ad_id' => '112', 'spend' => '20.50', 'impressions' => '500', 'clicks' => '5', 'actions' => [['action_type' => 'lead', 'value' => '1']]],
            ]]),
            'graph.facebook.com/*/act_111/adsets*' => Http::response(['data' => [
                ['id' => '11', 'name' => 'Atlanta 25mi', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '5000', 'campaign_id' => '1'],
            ]]),
            'graph.facebook.com/*/act_111/ads*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Video', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'adset_id' => '11'],
                ['id' => '112', 'name' => 'Photo', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'adset_id' => '11'],
            ]]),
            'graph.facebook.com/*/act_111/campaigns*' => Http::response(['data' => [
                ['id' => '1', 'name' => 'Atlanta - Meta Leads', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS'],
                ['id' => '2', 'name' => 'Boston - Engagement', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget' => '1000', 'objective' => 'OUTCOME_ENGAGEMENT'],
            ]]),
        ]);

        $campaigns = $this->signedInAs($this->userWithRole($t, 'Reader', ['ads_performance.view']))
            ->getJson('/api/v1/meta-ads/tree?connection_id='.$row->getKey().'&from=2026-10-01&to=2026-10-31')
            ->assertOk()->json('data.campaigns');

        $this->assertSame(50.5, $campaigns[0]['spend']);
        $this->assertSame(3, $campaigns[0]['leads']);
        $this->assertSame(16.83, $campaigns[0]['cost_per_lead']);
        $this->assertSame(50, $campaigns[0]['children'][0]['daily_budget']);
        $this->assertSame(50.5, $campaigns[0]['children'][0]['spend']);
        $this->assertSame(20.5, $campaigns[0]['children'][0]['children'][1]['spend']);
        // A campaign with no results still lists, at zero, with no cost per lead.
        $this->assertSame(0, $campaigns[1]['spend'] + $campaigns[1]['leads']);
        $this->assertNull($campaigns[1]['cost_per_lead']);
    }

    public function test_a_second_page_of_results_is_read_through_meta_s_own_next_link(): void
    {
        // A-1 Chimney, 2026-11-05: every read past 500 rows failed with
        // "(#200) Provide valid app ID", because an empty query array replaced
        // the next link's cursor and token.
        $t = $this->provisionTenant('Golf');
        $row = $this->account($t);
        $next = 'https://graph.facebook.com/v21.0/act_111/insights?after=CUR&access_token=user-token';
        Http::fake(function (Request $r) use ($next) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return match (true) {
                ! isset($q['access_token']) => Http::response(['error' => ['message' => '(#200) Provide valid app ID']], 400),
                str_contains($r->url(), '/insights') && ($q['after'] ?? null) === 'CUR' => Http::response(['data' => [
                    ['campaign_id' => '1', 'adset_id' => '11', 'ad_id' => '112', 'spend' => '20.00', 'impressions' => '1', 'clicks' => '1', 'actions' => []],
                ]]),
                str_contains($r->url(), '/insights') => Http::response(['data' => [
                    ['campaign_id' => '1', 'adset_id' => '11', 'ad_id' => '111', 'spend' => '30.00', 'impressions' => '1', 'clicks' => '1', 'actions' => []],
                ], 'paging' => ['next' => $next]]),
                str_contains($r->url(), '/campaigns') => Http::response(['data' => [['id' => '1', 'name' => 'Leads', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->signedInAs($this->userWithRole($t, 'Reader', ['ads_performance.view']))
            ->getJson('/api/v1/meta-ads/tree?connection_id='.$row->getKey())
            ->assertOk()->assertJsonPath('data.campaigns.0.spend', 50);
    }

    public function test_a_manager_pauses_an_ad_and_the_change_is_audited(): void
    {
        $t = $this->provisionTenant('Bravo');
        $row = $this->account($t);
        Http::fake([
            'graph.facebook.com/*/112?*' => Http::response(['id' => '112', 'account_id' => '111']),
            'graph.facebook.com/*/112' => Http::response(['success' => true]),
        ]);
        $actor = $this->manager($t);

        $this->signedInAs($actor)
            ->postJson('/api/v1/meta-ads/status', ['connection_id' => $row->getKey(), 'object_id' => '112', 'status' => 'PAUSED'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['status'] === 'PAUSED' && ! isset($r['daily_budget']));
        $event = $this->inTenant($t['org'], fn () => AuditEvent::query()->where('action', 'meta_ads.status_changed')->sole());
        $this->assertSame($actor->getKey(), $event->actor_id);
        $this->assertSame('112', $event->metadata['object_id']);
    }

    public function test_an_object_in_another_ad_account_is_refused_and_never_written(): void
    {
        $t = $this->provisionTenant('Charlie');
        $row = $this->account($t);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '999', 'account_id' => '222'])]);

        $this->signedInAs($this->manager($t))
            ->postJson('/api/v1/meta-ads/status', ['connection_id' => $row->getKey(), 'object_id' => '999', 'status' => 'PAUSED'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That is not in this ad account.');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_reading_spend_is_not_enough_to_change_it(): void
    {
        $t = $this->provisionTenant('Delta');
        $row = $this->account($t);
        Http::fake();

        $this->signedInAs($this->userWithRole($t, 'Reader', ['ads_performance.view']))
            ->postJson('/api/v1/meta-ads/budget', ['connection_id' => $row->getKey(), 'object_id' => '11', 'daily_budget' => 75])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_budget_goes_to_the_level_that_holds_it_in_cents(): void
    {
        $t = $this->provisionTenant('Echo');
        $row = $this->account($t);
        Http::fake([
            'graph.facebook.com/*/11?*' => Http::response(['id' => '11', 'account_id' => '111', 'daily_budget' => '5000']),
            'graph.facebook.com/*/11' => Http::response(['success' => true]),
            'graph.facebook.com/*/1?*' => Http::response(['id' => '1', 'account_id' => '111']),
        ]);

        $manager = $this->manager($t);
        $this->signedInAs($manager)
            ->postJson('/api/v1/meta-ads/budget', ['connection_id' => $row->getKey(), 'object_id' => '11', 'daily_budget' => 75])
            ->assertOk();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && (int) $r['daily_budget'] === 7500);
        $event = $this->inTenant($t['org'], fn () => AuditEvent::query()->where('action', 'meta_ads.budget_changed')->sole());
        $this->assertEquals(['from' => 50, 'to' => 75], $event->changes['daily_budget']);

        // A campaign whose budget lives on its ad sets says so, and nothing is written.
        $this->signedInAs($manager)
            ->postJson('/api/v1/meta-ads/budget', ['connection_id' => $row->getKey(), 'object_id' => '1', 'daily_budget' => 75])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This one has no budget of its own: it is set on its campaign or on its ad sets.');
        Http::assertSentCount(3);
    }
}
