<?php

namespace Tests\Feature\Social;

use App\Models\MetaAdCampaign;
use App\Models\MetaAdInsight;
use App\Models\ServiceGroup;
use App\Models\SocialConnection;
use App\Support\Social\MetaAdInsights;
use App\Support\Social\SocialOAuthDrivers;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Facebook Ads read into Reports (Jose, 2026-10-12). */
class MetaAdsTest extends TestCase
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
            'display_name' => 'A-1 Ads',
        ]), fn ($c) => $c->forceFill(['credentials' => ['access_token' => 'user-token']])->save()));
    }

    public function test_the_ads_driver_and_the_facebook_connect_both_ask_for_ads_read(): void
    {
        $driver = app(SocialOAuthDrivers::class)->byRouteKey('facebook-ads');

        $this->assertNotNull($driver);
        $this->assertSame(SocialProvider::FacebookAds, $driver->provider());
        $this->assertContains('ads_read', $driver->scopes());
        // And the Facebook connect asks for it too, so one connect brings the
        // ad accounts (Jose, 2026-10-14, reversing the separate-connect rule).
        $this->assertContains('ads_read', app(SocialOAuthDrivers::class)->byRouteKey('facebook')->scopes());
    }

    public function test_a_state_is_read_from_the_campaign_name(): void
    {
        $this->assertSame('TX', MetaAdInsights::stateIn('Texas - Chimney sweep leads'));
        $this->assertSame('CT', MetaAdInsights::stateIn('CT | Garage door'));
        $this->assertSame('NJ', MetaAdInsights::stateIn('Chimney New Jersey Fall'));
        // A full name wins over a two-letter word that happens to be a code.
        $this->assertSame('OH', MetaAdInsights::stateIn('Leads IN Ohio'));
        $this->assertNull(MetaAdInsights::stateIn('Brand awareness'));
    }

    public function test_campaigns_are_seeded_once_and_staff_edits_stand(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $connection = $this->account($t);
        $chimney = $this->inTenant($t['org'], fn () => ServiceGroup::create([
            'company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'name' => 'Chimney',
        ]));

        Http::fake(['graph.facebook.com/*/act_111/campaigns*' => Http::response(['data' => [
            ['id' => '900', 'name' => 'Texas Chimney Leads', 'objective' => 'OUTCOME_LEADS'],
        ]])]);

        $this->inTenant($t['org'], fn () => app(MetaAdInsights::class)->campaigns($connection));
        $campaign = MetaAdCampaign::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($chimney->id, $campaign->service_group_id);
        $this->assertSame('TX', $campaign->state);

        // Staff say it is really Georgia's; the next sync must not undo that.
        $this->inTenant($t['org'], fn () => $campaign->forceFill(['state' => 'GA'])->save());
        $this->inTenant($t['org'], fn () => app(MetaAdInsights::class)->campaigns($connection));
        $this->assertSame('GA', $campaign->fresh()->state);
    }

    public function test_daily_rows_land_as_totals_and_by_region_and_a_resync_does_not_double(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $connection = $this->account($t);

        $row = fn (?string $region) => array_filter([
            'date_start' => '2026-10-10', 'campaign_id' => '900', 'adset_id' => '77', 'adset_name' => 'Houston',
            'spend' => '12.50', 'impressions' => '1000', 'clicks' => '20', 'account_currency' => 'USD',
            'actions' => [['action_type' => 'link_click', 'value' => '20'], ['action_type' => 'lead', 'value' => '3']],
            'region' => $region,
        ]);

        Http::fake(function ($request) use ($row) {
            return Http::response(['data' => [str_contains($request->url(), 'breakdowns=region') ? $row('Texas') : $row(null)]]);
        });

        $sync = fn () => $this->inTenant($t['org'], fn () => app(MetaAdInsights::class)
            ->sync($connection, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-10'), true));

        $sync();
        $sync();

        $rows = MetaAdInsight::query()->withoutGlobalScopes()->orderBy('region')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['', 'Texas'], $rows->pluck('region')->all());
        $this->assertSame('12.50', (string) $rows[0]->spend);
        // "lead", not every action: 3, not 23.
        $this->assertSame(3, (int) $rows[0]->leads);

        // Reports' read of totals, in cents.
        $ads = app(MetaAdInsights::class);
        $totals = $ads->rows($t['org'], '2026-10-01', '2026-10-31');
        $this->assertCount(1, $totals);
        $this->assertSame(1250, $totals[0]['spend_minor']);
        $this->assertNull($totals[0]['region']);
    }
}
