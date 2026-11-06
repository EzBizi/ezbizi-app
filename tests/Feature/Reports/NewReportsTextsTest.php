<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Services\Ads\CampaignPerformance;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\Texts\DailyTexts;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose's NEW REPORTS lists (2026-11-06): revenue collected at 1 AM, jobs
 * created and Google and Meta Ads at 8 PM, and the Sunday weekly; every
 * branch in the business's location order.
 */
class NewReportsTextsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Franchise $nj;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->nj = $this->inTenant($this->t['org'], fn () => Franchise::query()->create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id, 'name' => 'New Jersey', 'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]));
        // NJ first, as Jose's list has it; the tenant's own branch is not listed, so it comes after.
        $this->inTenant($this->t['org'], fn () => DB::table('business_list_items')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id,
            'list_key' => 'reports.locations', 'item_key' => 'NJ', 'value' => json_encode(['branch' => 'New Jersey', 'code' => 'NJ']), 'active' => true, 'sort' => 0,
            'created_at' => now(), 'updated_at' => now()]));
    }

    private function text(string $kind, string $day = '2026-11-08'): string
    {
        return $this->inTenant($this->t['org'], fn () => app(DailyTexts::class)->render($kind, $this->t['org'], CarbonImmutable::parse($day)));
    }

    private function job(Franchise $at, string $id, string $source, string $booked, array $tags = []): void
    {
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $at->id, 'remote_id' => $id, 'job_source' => $source,
            'status' => 'Submitted', 'booked_at' => $booked, 'payload' => ['Tags' => $tags], 'synced_at' => now()]));
    }

    private function record(Franchise $at, string $type, string $id, string $when, ?float $amount, array $payload = []): void
    {
        DB::table('workiz_records')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'franchise_id' => $at->id,
            'type' => $type, 'remote_id' => $id, 'starts_at' => $when, 'amount' => $amount, 'payload' => json_encode($payload),
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function googleAds(Franchise $at): void
    {
        $this->inTenant($this->t['org'], function () use ($at) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleAds->value, 'remote_id' => (string) random_int(1000000000, 9999999999), 'display_name' => 'Ads '.$at->name,
                'leads_franchise_id' => $at->id, 'is_manager' => false]);
            $c->connect(['refresh_token' => 'r']);
            $c->save();
        });
    }

    public function test_revenue_collected_is_yesterdays_payments_per_location_in_the_business_order(): void
    {
        $this->record($this->nj, 'payment', 'P1', '2026-11-07 10:00:00', 100);
        $this->record($this->nj, 'payment', 'P2', '2026-11-07 23:30:00', 50.5);
        $this->record($this->nj, 'payment', 'P3', '2026-11-08 00:10:00', 999);
        $this->record($this->t['franchise'], 'payment', 'P4', '2026-11-07 12:00:00', 20);

        $text = $this->text('revenue_collected');

        $this->assertStringContainsString('Sat, Nov 7, 2026', $text);
        $this->assertStringContainsString("• *New Jersey*: $150.50\n• *".$this->t['franchise']->name.'*: $20.00', $text);
        $this->assertStringContainsString('*TOTAL: $170.50*', $text);
        $this->assertStringNotContainsString('999', $text);
    }

    public function test_jobs_created_leave_out_paid_estimated_however_workiz_decorates_it(): void
    {
        $this->job($this->nj, 'J1', 'LS Ads', '2026-11-08 09:00:00');
        $this->job($this->nj, 'J2', 'LS Ads', '2026-11-08 10:00:00', ["\u{2060}\u{1F7E2} Paid \u{2013} Estimated"]);
        $this->job($this->nj, 'J3', 'PPC', '2026-11-08 11:00:00', [" \u{2060}\u{1F535} Paid \u{2013} Estimated (By Sub)"]);
        $this->job($this->nj, 'J4', 'PPC', '2026-11-08 12:00:00', ["\u{1F534} Free \u{2013} Estimated"]);
        $this->job($this->nj, 'J5', 'PPC', '2026-11-07 12:00:00');

        $text = $this->text('evening');

        $this->assertStringContainsString('• *New Jersey*: 2', $text);
        $this->assertStringContainsString('*TOTAL: 2 jobs*', $text);
    }

    public function test_google_ads_counts_active_campaigns_against_google_jobs(): void
    {
        $this->mock(CampaignPerformance::class)->shouldReceive('channelCost')
            ->withArgs(fn ($connection, $from, $to, $activeOnly = false) => $activeOnly === true)
            ->andReturn(['currency' => 'USD', 'lsa' => ['cost_minor' => 20000, 'clicks' => 0, 'conversions' => 0.0],
                'ppc' => ['cost_minor' => 10000, 'clicks' => 5, 'conversions' => 0.0]]);
        $this->googleAds($this->nj);
        $this->job($this->nj, 'J1', 'LS Ads', '2026-11-08 09:00:00');
        $this->job($this->nj, 'J2', 'PPC', '2026-11-08 10:00:00');
        $this->job($this->nj, 'J3', 'GMB /SEO', '2026-11-08 10:00:00');

        $text = $this->text('evening');

        $this->assertStringContainsString('• *New Jersey*: spent $300.00 · 2 jobs · $150.00 per job', $text);
        $this->assertStringContainsString('• *TOTAL*: spent $300.00 · 2 jobs', $text);
    }

    public function test_meta_ads_place_a_campaign_by_its_state_code_and_count_forms(): void
    {
        $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::FacebookAds->value, 'remote_id' => 'act_1', 'display_name' => 'Meta']);
            $c->connect(['access_token' => 't']);
            $c->save();
            foreach ([['C1', 'NJ', 80.0, 4], ['C2', 'TX', 25.0, 1], ['C3', 'NJ', 0.0, 0]] as [$id, $state, $spend, $leads]) {
                DB::table('meta_ad_campaigns')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'connection_id' => $c->id,
                    'campaign_id' => $id, 'name' => $id, 'state' => $state, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('meta_ad_insights')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'connection_id' => $c->id,
                    'date' => '2026-11-08', 'campaign_id' => $id, 'adset_id' => 'A'.$id, 'region' => '', 'spend' => $spend, 'leads' => $leads,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->job($this->nj, 'J1', 'Meta', '2026-11-08 09:00:00');

        $text = $this->text('evening');

        $this->assertStringContainsString('• *New Jersey*: spent $80.00 · 1 job · $80.00 per job · 4 forms', $text);
        $this->assertStringContainsString('1 other campaign spent $25.00', $text);
    }

    public function test_weekly_dispatchers_list_dispatchers_before_remote_dispatchers(): void
    {
        foreach (['Rina' => 'Remote Dispatcher', 'Dov' => 'Dispatcher'] as $name => $role) {
            $user = $this->userWithRole($this->t, $role, [], strtolower($name).'@alpha.test');
            $this->inTenant($this->t['org'], fn () => $user->forceFill(['name' => $name])->save());
        }
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([
            'Rina' => ['person' => 'Rina', 'new_client' => 9, 'existing_client' => 2, 'jobs' => 4],
            'Dov' => ['person' => 'Dov', 'new_client' => 3, 'existing_client' => 0, 'jobs' => 1],
        ]);

        $text = $this->text('weekly');

        $new = substr($text, strpos($text, 'new clients*'), strpos($text, 'existing clients*') - strpos($text, 'new clients*'));
        $this->assertStringContainsString("*Dispatcher*\n• Dov — 3 calls · 1 job created\n\n*Remote Dispatcher*\n• Rina — 9 calls · 4 jobs created", $new);
        $this->assertStringContainsString("existing clients*\n_Nov 2 - Nov 8, 2026_\n\n*Remote Dispatcher*\n• Rina — 2 calls", $text);
    }

    public function test_weekly_local_services_reports_only_the_losing_locations(): void
    {
        $this->mock(CampaignPerformance::class)->shouldReceive('channelCost')->andReturn(['currency' => 'USD',
            'lsa' => ['cost_minor' => 50000, 'clicks' => 0, 'conversions' => 0.0], 'ppc' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0]]);
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([]);
        $this->googleAds($this->nj);
        $this->googleAds($this->t['franchise']);
        $this->record($this->nj, 'web_sale', 'WS-1', '2026-11-03 10:00:00', 400, ['ad_group' => 'LS Ads', 'profit' => 300]);
        $this->record($this->nj, 'web_sale', 'WS-2', '2026-11-04 10:00:00', 900, ['ad_group' => 'GMB /SEO', 'profit' => 900]);
        $this->record($this->t['franchise'], 'web_sale', 'WS-3', '2026-11-05 10:00:00', 900, ['ad_group' => 'LS Ads', 'profit' => '800.00']);

        $text = $this->text('weekly');
        $lsa = substr($text, strpos($text, 'Local Services · locations losing money'));

        $this->assertStringContainsString('• *New Jersey*: -$200.00 · profit $300.00 on 1 job · spent $500.00', $lsa);
        $this->assertStringNotContainsString($this->t['franchise']->name, $lsa);
    }

    public function test_the_weekly_text_holds_every_section_and_in_progress_uses_the_business_tags(): void
    {
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([]);
        $this->inTenant($this->t['org'], fn () => app(BusinessLists::class)->rows('reports.tags_not_in_progress'));

        $text = $this->text('weekly');

        foreach (['*Weekly report*', '*Revenue collected*', 'In Progress jobs by technician*', '*Jobs created*', 'new clients*', 'existing clients*',
            '*Google Ads · active campaigns*', '*Meta Ads · active campaigns*', 'Local Services · locations losing money*'] as $section) {
            $this->assertStringContainsString($section, $text);
        }
        $this->assertStringContainsString('_Excludes jobs tagged Deposit Received, Deposit Recieved._', $text);
        $this->assertStringContainsString('Nov 2 - Nov 8, 2026', $text);
    }
}
