<?php

namespace Tests\Feature\Reports;

use App\Models\FinancialResult;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Services\Ads\CampaignPerformance;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\Texts\DailyTexts;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MapsAirtableTables;
use Tests\TestCase;

/** The daily and weekly report texts, for any organization (2026-10-20). */
class DailyTextsTest extends TestCase
{
    use BuildsTenants;
    use MapsAirtableTables;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
    }

    private function text(string $kind, string $day = '2026-10-20'): string
    {
        return $this->inTenant($this->t['org'], fn () => app(DailyTexts::class)->render($kind, $this->t['org'], CarbonImmutable::parse($day)));
    }

    public function test_technician_sales_per_branch_with_a_total(): void
    {
        $f = $this->t['franchise'];
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-1', 'job_source' => 'GMB',
            'status' => 'Done', 'booked_at' => '2026-10-19 09:00:00', 'last_status_update' => '2026-10-20 10:00:00',
            'payload' => ['Team' => [['id' => 'U1', 'Name' => 'Ann']], 'JobTotalPrice' => 450], 'synced_at' => now()]));

        $text = $this->text('technician_sales');

        $this->assertStringContainsString('• *Ann*: 1 completed · 0 canceled · $450.00 sales', $text);
        $this->assertStringContainsString('*Total:* 1 completed', $text);
    }

    public function test_ppc_spend_against_ppc_jobs_per_branch_with_google_ads(): void
    {
        $this->mock(CampaignPerformance::class)->shouldReceive('channelCost')->andReturn(['currency' => 'USD',
            'lsa' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0],
            'ppc' => ['cost_minor' => 10000, 'clicks' => 40, 'conversions' => 3.0]]);
        $f = $this->t['franchise'];
        $this->inTenant($this->t['org'], function () use ($f) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleAds->value, 'remote_id' => '1234567890', 'display_name' => 'Ads', 'leads_franchise_id' => $f->id, 'is_manager' => false]);
            $c->connect(['refresh_token' => 'r']);
            $c->save();
        });
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-2', 'job_source' => 'PPC',
            'status' => 'Submitted', 'booked_at' => '2026-10-20 11:00:00', 'payload' => [], 'synced_at' => now()]));

        $text = $this->text('ppc');

        $this->assertStringContainsString('Spent: $100.00 · Clicks: 40', $text);
        $this->assertStringContainsString('Average per click: $2.50', $text);
        $this->assertStringContainsString('PPC jobs created: 1 · Cost per job: $100.00', $text);
        $this->assertStringContainsString('*TOTAL*', $text);
    }

    public function test_dispatchers_from_performance_people(): void
    {
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([
            'Ada' => ['person' => 'Ada', 'answered' => 10, 'new_client' => 4, 'jobs' => 2],
            'Bo' => ['person' => 'Bo', 'answered' => 0, 'new_client' => 0, 'jobs' => 0],
        ]);

        $text = $this->text('dispatchers');

        $this->assertStringContainsString('• *Ada*: 2 jobs created · 10 answered · 50.0% of new-client calls', $text);
        $this->assertStringNotContainsString('Bo', $text);
    }

    public function test_weekly_losses_list_only_negative_branches(): void
    {
        $f = $this->t['franchise'];
        $this->airtableTable($this->t, (string) $f->id, 'pnl', [
            ['Week' => '12 Oct - 18 Oct', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '12 Oct - 18 Oct', 'Marketing' => 'Ads', 'Marketing Amount' => 1500],
        ]);

        $text = $this->text('weekly_losses');

        $this->assertStringContainsString('• *1 branch with negative net profit*', $text);
        $this->assertStringContainsString('• Net profit: -$500.00', $text);
    }

    public function test_weekly_reviews_split_positive_and_negative(): void
    {
        foreach ([5, 4, 2, 5] as $i => $rating) {
            DB::table('reviews')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'franchise_id' => $this->t['franchise']->id,
                'remote_name' => 'r'.$i, 'location_name' => 'L', 'rating' => $rating, 'reviewed_at' => '2026-10-14 15:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }

        $text = $this->text('weekly_reviews');

        $this->assertStringContainsString('*Total: 4 reviews*', $text);
        $this->assertStringContainsString('3 positive', $text);
        $this->assertStringContainsString('1 negative', $text);
    }

    public function test_marketing_ppc_reads_the_mapped_table_latest_day(): void
    {
        $this->airtableTable($this->t, null, 'marketing', [
            ['DATE' => '2026-10-19', 'Service Area' => 'Austin', 'COST' => 200, 'CALLS' => 10, 'JOBS' => 4],
            ['DATE' => '2026-10-18', 'Service Area' => 'Austin', 'COST' => 999, 'CALLS' => 1, 'JOBS' => 1],
        ]);

        $text = $this->text('marketing_ppc');

        $this->assertStringContainsString('*Data date: Oct 19*', $text);
        $this->assertStringContainsString('• Spent: $200.00 · Calls: 10 · Jobs: 4', $text);
        $this->assertStringContainsString('• Cost/job: $50.00 · Call-to-job: 40.0%', $text);
        $this->assertStringNotContainsString('999', $text);
    }

    /** Meta, 2026-10-27: approved ACE results entered in EzBizi count; they replace Airtable's same day and area; pending never shows. */
    public function test_marketing_ppc_reads_approved_results_over_airtable(): void
    {
        $this->airtableTable($this->t, null, 'marketing', [
            ['DATE' => '2026-10-19', 'Service Area' => 'Austin', 'COST' => 999, 'CALLS' => 1, 'JOBS' => 1],
            ['DATE' => '2026-10-19', 'Service Area' => 'Dallas', 'COST' => 100, 'CALLS' => 5, 'JOBS' => 1],
        ]);
        $this->inTenant($this->t['org'], function () {
            $row = fn (string $area, int $cost, string $status) => FinancialResult::query()->create(['source' => 'ACE', 'date' => '2026-10-19',
                'area' => $area, 'calls' => 10, 'jobs' => 4, 'cost_cents' => $cost, 'status' => $status]);
            $row('Austin', 20000, 'approved');
            $row('Dallas', 77700, 'pending');
        });

        $text = $this->text('marketing_ppc');

        $this->assertStringContainsString("*Austin*\n• Spent: $200.00 · Calls: 10 · Jobs: 4", $text);
        $this->assertStringContainsString("*Dallas*\n• Spent: $100.00 · Calls: 5 · Jobs: 1", $text);
        $this->assertStringNotContainsString('999', $text);
        $this->assertStringNotContainsString('777', $text);
    }
}
