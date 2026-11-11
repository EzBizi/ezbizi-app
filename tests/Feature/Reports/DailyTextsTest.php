<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancialResult;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
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
            'status' => 'Done', 'booked_at' => '2026-10-19 09:00:00', 'scheduled_for' => '2026-10-20 08:00:00', 'last_status_update' => '2026-10-20 10:00:00',
            'payload' => ['Team' => [['id' => 'U1', 'Name' => 'Ann']], 'JobTotalPrice' => 450], 'synced_at' => now()]));

        $text = $this->text('technician_sales');

        $this->assertStringContainsString('• *Ann*: 1 completed · 0 canceled · $450.00 sales', $text);
        $this->assertStringContainsString("\u{1F4B0} *Total:* \u{2705} 1 completed", $text);
    }

    /** Audit, 2026-11-10: Ohio's 19 done were 17 jobs; a job two worked is one in the total. */
    public function test_technician_sales_total_counts_a_shared_job_once(): void
    {
        $f = $this->t['franchise'];
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-9', 'job_source' => 'GMB',
            'status' => 'Done', 'booked_at' => '2026-10-19 09:00:00', 'scheduled_for' => '2026-10-20 08:00:00', 'last_status_update' => '2026-10-20 10:00:00',
            'payload' => ['Team' => [['id' => 'U1', 'Name' => 'Ann'], ['id' => 'U2', 'Name' => 'Bo']], 'JobTotalPrice' => 300], 'synced_at' => now()]));

        $text = $this->text('technician_sales');

        $this->assertStringContainsString('• *Ann*: 1 completed · 0 canceled · $150.00 sales', $text);
        $this->assertStringContainsString('• *Bo*: 1 completed · 0 canceled · $150.00 sales', $text);
        $this->assertStringContainsString("\u{1F4B0} *Total:* \u{2705} 1 completed · \u{274C} 0 canceled · \u{1F4B5} \$300.00 sales", $text);
    }

    /**
     * Desk, 2026-11-11 (audit #2, #3): the daily counts the jobs SCHEDULED that day, as Workiz's technician
     * report does, so a repair sold weeks ago and finished today is not today's; a deposit is the job's team's.
     */
    public function test_technician_sales_count_the_days_scheduled_jobs_and_credit_deposits_to_the_jobs_team(): void
    {
        $f = $this->t['franchise'];
        $this->inTenant($this->t['org'], function () use ($f) {
            WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-OLD', 'job_source' => 'GMB', 'status' => 'Done',
                'booked_at' => '2026-09-01 09:00:00', 'scheduled_for' => '2026-10-19 08:00:00', 'last_status_update' => '2026-10-20 10:00:00',
                'payload' => ['Team' => [['id' => 'U1', 'Name' => 'Ann']], 'JobTotalPrice' => 9000], 'synced_at' => now()]);
            WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-NEW', 'job_source' => 'GMB', 'status' => 'Done',
                'booked_at' => '2026-10-18 09:00:00', 'scheduled_for' => '2026-10-20 08:00:00', 'last_status_update' => '2026-10-21 10:00:00',
                'payload' => ['Team' => [['id' => 'U2', 'Name' => 'Bo']], 'JobTotalPrice' => 200, 'v2' => ['id' => 'JOB-v2new']], 'synced_at' => now()]);
            // Keyed in by the office on Bo's job.
            WorkizRecord::query()->create(['franchise_id' => $f->id, 'type' => 'payment', 'remote_id' => 'PAY-1', 'user_remote_id' => 'USR-office',
                'job_remote_id' => 'JOB-v2new', 'amount' => 120, 'starts_at' => '2026-10-20 12:00:00', 'payload' => [], 'synced_at' => now()]);
        });

        $text = $this->text('technician_sales');

        $this->assertStringContainsString('• *Bo*: 1 completed · 0 canceled · $200.00 sales · $120.00 deposits', $text);
        $this->assertStringNotContainsString('Ann', $text);
        $this->assertStringNotContainsString('USR-office', $text);
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
        // Not a job created, here as in every report (audit, 2026-11-10: only the daily texts left it out).
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'JOB-3', 'job_source' => 'PPC',
            'status' => 'Submitted', 'booked_at' => '2026-10-20 12:00:00', 'payload' => ['Tags' => ['Paid – Estimated']], 'synced_at' => now()]));

        $text = $this->text('ppc');

        $this->assertStringContainsString('Spent: $100.00 · Clicks: 40', $text);
        $this->assertStringContainsString('Average per click: $2.50', $text);
        $this->assertStringContainsString('PPC jobs created: 1 · Cost per job: $100.00', $text);
        $this->assertStringContainsString('*TOTAL*', $text);
    }

    public function test_dispatchers_from_performance_people(): void
    {
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([
            'Ada' => ['person' => 'Ada', 'answered' => 10, 'new_client' => 4, 'booked_new' => 2, 'jobs' => 3],
            'Bo' => ['person' => 'Bo', 'answered' => 0, 'new_client' => 0, 'jobs' => 0],
        ]);

        $text = $this->text('dispatchers');

        // Booked out of answered new-client calls, not jobs over them (Trey, 2026-11-04).
        $this->assertStringContainsString('• *Ada*: 3 jobs created · 10 answered · 50.0% of new-client calls booked', $text);
        $this->assertStringNotContainsString('Bo', $text);
    }

    /** Desk, 2026-10-27: a rule limited to some branches never gets the whole organization's figures. */
    public function test_a_branch_rule_gets_only_its_branches_marketing_and_no_dispatchers(): void
    {
        $this->mock(PerformancePeople::class)->shouldNotReceive('for');
        $this->airtableTable($this->t, null, 'marketing', [['DATE' => '2026-10-19', 'Service Area' => 'HQ area', 'COST' => 999, 'CALLS' => 1, 'JOBS' => 1]]);
        $this->airtableTable($this->t, (string) $this->t['franchise']->id, 'marketing', [['DATE' => '2026-10-19', 'Service Area' => 'Branch area', 'COST' => 200, 'CALLS' => 10, 'JOBS' => 4]]);
        $render = fn (string $kind) => $this->inTenant($this->t['org'], fn () => app(DailyTexts::class)
            ->render($kind, $this->t['org'], CarbonImmutable::parse('2026-10-20'), [(string) $this->t['franchise']->id]));

        $text = $render('marketing_ppc');
        $this->assertStringContainsString('Branch area', $text);
        $this->assertStringNotContainsString('HQ area', $text);
        $this->assertStringContainsString('No phone account answers for these branches alone', $render('dispatchers'));
    }

    /**
     * 2026-11-04: A-1's rule read EverDoors' phones, first() of two accounts
     * with nobody signed in. A rule reads the accounts that answer for its
     * branches alone; the organization's reads each, a section apiece.
     */
    public function test_dispatchers_read_the_phone_accounts_the_rule_covers(): void
    {
        $t = $this->t;
        [$ours, $theirs] = $this->inTenant($t['org'], function () use ($t) {
            $other = Company::query()->create(['organization_id' => $t['org']->id, 'name' => 'Doors', 'is_active' => true]);
            $doors = Franchise::query()->create(['organization_id' => $t['org']->id, 'company_id' => $other->id, 'name' => 'Doors CT',
                'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]);
            $phone = function (string $type, string $scope, string $name) use ($t) {
                $c = new SocialConnection;
                $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => $type, 'scope_id' => $scope,
                    'provider' => SocialProvider::RingCentral->value, 'remote_id' => Str::random(6), 'display_name' => $name]);
                $c->connect(['jwt' => 'j']);
                $c->save();

                return (string) $c->id;
            };

            return [$phone('company', (string) $t['company']->id, 'Chimney'), $phone('franchise', (string) $doors->id, 'Doors')];
        });
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturnUsing(fn ($u, $from, $to, $connection) => match ($connection) {
            $ours => ['Ada' => ['person' => 'Ada', 'answered' => 5, 'new_client' => 0, 'jobs' => 1]],
            $theirs => ['Eve' => ['person' => 'Eve', 'answered' => 7, 'new_client' => 0, 'jobs' => 2]],
        });
        $render = fn (?array $branches) => $this->inTenant($t['org'], fn () => app(DailyTexts::class)
            ->render('dispatchers', $t['org'], CarbonImmutable::parse('2026-10-20'), $branches));

        $mine = $render([(string) $t['franchise']->id]);
        $this->assertStringContainsString('*Ada*', $mine);
        $this->assertStringNotContainsString('Eve', $mine);

        $all = $render(null);
        $this->assertStringContainsString("*Chimney*\n*Jobs created: 1*", $all);
        $this->assertStringContainsString("*Doors*\n*Jobs created: 2*", $all);
    }

    /** Desk, 2026-10-29: A1 Leads' 8:30 assignments, from EzBizi's own jobs; Deposit Received left out. */
    public function test_job_assignments_count_today_in_progress_per_technician(): void
    {
        $this->inTenant($this->t['org'], function () {
            $b = $this->t['franchise'];
            $tech = (string) Str::uuid();
            DB::table('scheduling_technicians')->insert(['id' => $tech, 'organization_id' => $this->t['org']->id, 'franchise_id' => $b->id, 'name' => 'Ann', 'created_at' => now(), 'updated_at' => now()]);
            $customer = Customer::factory()->create(['owning_franchise_id' => $b->id]);
            $job = fn (string $status, string $when, array $tags = []) => Job::factory()->create(['franchise_id' => $b->id, 'customer_id' => $customer->id, 'status' => $status, 'scheduled_for' => $when, 'tags' => $tags]);
            foreach ([$job('In progress', '2026-10-20 09:00:00'), $job('In Progress', '2026-10-20 15:00:00')] as $j) {
                DB::table('job_technicians')->insert(['job_id' => $j->id, 'technician_id' => $tech, 'source' => 'ezbizi', 'created_at' => now(), 'updated_at' => now()]);
            }
            $job('In progress', '2026-10-20 11:00:00');
            $job('In progress', '2026-10-20 12:00:00', ['Deposit Received']);
            $job('In progress', '2026-10-20 13:00:00', ['DEPOSIT RECEIVED']);
            $job('In progress', '2026-10-21 09:00:00');
            $job('Done', '2026-10-20 09:00:00');
        });

        $text = $this->text('job_assignments');

        $this->assertStringContainsString('• Ann — 2 jobs', $text);
        $this->assertStringContainsString('Unassigned — 1 job', $text);
        $this->assertStringContainsString('*All branches — 3 In Progress jobs*', $text);
        $this->assertStringContainsString('2 assigned · 1 unassigned', $text);
        $this->assertStringContainsString('measured ', $text);
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

    /** Audit, 2026-11-10: a branch with no P&L table is said apart, not counted as a week not in yet. */
    public function test_weekly_losses_name_a_branch_with_no_pnl_table_apart(): void
    {
        $f = $this->t['franchise'];
        $this->airtableTable($this->t, (string) $f->id, 'pnl', [
            ['Week' => '12 Oct - 18 Oct', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '12 Oct - 18 Oct', 'Marketing' => 'Ads', 'Marketing Amount' => 1500],
        ]);
        $this->inTenant($this->t['org'], fn () => Franchise::query()->create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id, 'name' => 'Massachusetts', 'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]));

        $text = $this->text('weekly_losses');

        $this->assertStringContainsString('• *1 branch with negative net profit*', $text);
        $this->assertStringContainsString('_No P&L table mapped: ', $text);
        $this->assertStringContainsString('Massachusetts_', $text);
        $this->assertStringNotContainsString("can't be judged", $text);
    }

    /** Audit, 2026-11-10: spend on an account linked to no branch is said, not dropped ($4,645.62 on Nov 9). */
    public function test_google_ads_say_the_spend_of_accounts_linked_to_no_branch(): void
    {
        $this->mock(CampaignPerformance::class)->shouldReceive('channelCost')->andReturn(['currency' => 'USD',
            'lsa' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0],
            'ppc' => ['cost_minor' => 10000, 'clicks' => 40, 'conversions' => 3.0]]);
        $f = $this->t['franchise'];
        $this->inTenant($this->t['org'], function () use ($f) {
            foreach ([['1234567890', $f->id], ['2222222222', null]] as [$remote, $branch]) {
                $c = new SocialConnection;
                $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $this->t['company']->id,
                    'provider' => SocialProvider::GoogleAds->value, 'remote_id' => $remote, 'display_name' => 'Ads '.$remote, 'leads_franchise_id' => $branch, 'is_manager' => false]);
                $c->connect(['refresh_token' => 'r']);
                $c->save();
            }
        });

        $text = $this->text('evening');

        $this->assertStringContainsString("\u{26A0}\u{FE0F} 1 Google Ads account linked to no branch spent $100.00; it is not in the figures above.", $text);
    }

    /** Desk, 2026-11-03: no data is no text, never an empty report. */
    public function test_marketing_ppc_with_no_entries_is_no_text(): void
    {
        $this->assertSame('', $this->text('marketing_ppc'));
    }

    /**
     * Desk, 2026-11-03: a week with no complete P&L is not an all-clear. 2026-11-11: it is no text at all,
     * so the rule, hourly on Wednesdays, sends once the P&L lands.
     */
    public function test_weekly_losses_without_a_complete_week_sends_nothing_yet(): void
    {
        $this->assertSame('', $this->text('weekly_losses'));
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

    /** Audit #12, 2026-11-11: a review Google took down is not the week's (47 counted, 46 there). */
    public function test_weekly_reviews_leave_out_a_removed_review(): void
    {
        foreach ([null, '2026-10-16 10:00:00'] as $i => $removed) {
            DB::table('reviews')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'franchise_id' => $this->t['franchise']->id,
                'remote_name' => 'r'.$i, 'location_name' => 'L', 'rating' => 5, 'reviewed_at' => '2026-10-14 15:00:00', 'removed_at' => $removed,
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertStringContainsString('*Total: 1 reviews*', $this->text('weekly_reviews'));
    }

    /** Audit #9, 2026-11-11: Rodrigo held a report back on stale Workiz; ours goes out and says so, and only when it is. */
    public function test_a_report_says_when_workiz_is_over_two_hours_behind(): void
    {
        $f = $this->t['franchise'];
        $job = fn (string $ref, string $synced) => $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => $ref,
            'job_source' => 'GMB', 'status' => 'Done', 'scheduled_for' => '2026-10-20 08:00:00', 'last_status_update' => '2026-10-20 10:00:00',
            'payload' => ['Team' => [['id' => 'U1', 'Name' => 'Ann']], 'JobTotalPrice' => 100], 'synced_at' => $synced]));
        $job('JOB-1', '2026-10-20 15:00:00');

        $this->assertStringNotContainsString('last synced', $this->text('technician_sales', '2026-10-20 16:00:00'));
        $this->assertStringContainsString("\u{26A0}\u{FE0F} Workiz jobs last synced Oct 20, 3:00 PM for ".$f->name.': anything since is not in these figures.',
            $this->text('technician_sales', '2026-10-20 18:00:00'));
    }

    public function test_marketing_ppc_reads_the_mapped_table_latest_day(): void
    {
        $this->airtableTable($this->t, null, 'marketing', [
            ['DATE' => '2026-10-19', 'Service Area' => 'Austin', 'COST' => 200, 'CALLS' => 10, 'JOBS' => 4],
            ['DATE' => '2026-10-18', 'Service Area' => 'Austin', 'COST' => 999, 'CALLS' => 1, 'JOBS' => 1],
        ]);

        $text = $this->text('marketing_ppc');

        $this->assertStringContainsString('*Data date: Mon, Oct 19*', $text);
        $this->assertStringContainsString('• Spent: $200.00 · Calls: 10 · Jobs: 4', $text);
        $this->assertStringContainsString('• Cost/job: $50.00 · Call-to-job: 40.0%', $text);
        $this->assertStringNotContainsString('999', $text);
    }

    /** 2026-11-09: ACE's sheet held Nov 8 as zeros in every area (not entered yet) and has no CALLS column. */
    public function test_marketing_ppc_skips_a_day_of_zeros_and_a_table_without_calls(): void
    {
        $this->airtableTable($this->t, null, 'marketing', [
            ['DATE' => '2026-10-19', 'Service Area' => 'Cleveland ', 'COST' => 0, 'JOBS' => 0, 'Clicks' => 0],
            ['DATE' => '2026-10-17', 'Service Area' => 'Cleveland ', 'COST' => 252.31, 'JOBS' => 2, 'Clicks' => 16],
        ]);

        $text = $this->text('marketing_ppc');

        $this->assertStringContainsString('*Data date: Sat, Oct 17*', $text);
        $this->assertStringContainsString("*Cleveland*\n• Spent: $252.31 · Jobs: 2\n• Cost/job: $126.15\n", $text);
        $this->assertStringContainsString('*TOTAL* · Spent: $252.31 · Jobs: 2', $text);
        $this->assertStringNotContainsString('Calls', $text);
    }

    /**
     * Jose, 2026-11-10: a company's sheet keeps its spend, but its jobs are the
     * ones Workiz credits to that company, by service area, that day; stale
     * spend is said.
     */
    public function test_marketing_ppc_counts_a_companys_jobs_from_ezbizi_not_its_sheet(): void
    {
        $ace = $this->inTenant($this->t['org'], fn () => Franchise::query()->create(['organization_id' => $this->t['org']->id,
            'company_id' => Company::query()->create(['organization_id' => $this->t['org']->id, 'parent_company_id' => $this->t['company']->id,
                'name' => 'Ace Fireplace Services', 'is_active' => true])->id,
            'name' => 'Main', 'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]));
        $this->airtableTable($this->t, $ace->id, 'marketing', [
            ['DATE' => '2026-10-17', 'Service Area' => 'Cleveland ', 'COST' => 300, 'JOBS' => 0],
            ['DATE' => '2026-10-19', 'Service Area' => 'Cleveland ', 'COST' => 0, 'JOBS' => 0],
        ]);
        $job = fn (string $ref, string $source, string $area, string $at) => WorkizJob::query()->create(['franchise_id' => $this->t['franchise']->id,
            'remote_id' => $ref, 'job_source' => $source, 'service_area' => $area, 'status' => 'Submitted', 'booked_at' => $at, 'payload' => [], 'synced_at' => now()]);
        $this->inTenant($this->t['org'], function () use ($job) {
            $job('JOB-1', 'Ace Fireplace Services', 'Cleveland', '2026-10-17 09:00:00');
            $job('JOB-2', 'Ace Fireplace Services', 'Cleveland', '2026-10-17 15:00:00');
            $job('JOB-3', 'Ace Fireplace Services', 'DFW', '2026-10-17 10:00:00');
            $job('JOB-4', 'GMB', 'Cleveland', '2026-10-17 10:00:00');
            $job('JOB-5', 'Ace Fireplace Services', 'Cleveland', '2026-10-18 10:00:00');
            $job('JOB-6', 'Ace FireplaceServices', 'DFW', '2026-10-17 11:00:00');
        });

        $text = $this->inTenant($this->t['org'], fn () => app(DailyTexts::class)->render('marketing_ppc', $this->t['org'], CarbonImmutable::parse('2026-10-20'), [$ace->id]));

        $this->assertStringContainsString("*Data date: Sat, Oct 17*\n\u{26A0}\u{FE0F} Spend last updated Oct 17\n_Spend from the Ace Fireplace Services sheet · jobs from EzBizi_", $text);
        $this->assertStringContainsString("*Cleveland*\n• Spent: $300.00 · Jobs: 2\n• Cost/job: $150.00", $text);
        $this->assertStringContainsString("*DFW*\n• Spent: N/A · Jobs: 2\n• Cost/job: N/A", $text);
        $this->assertStringContainsString('*TOTAL* · Spent: $300.00 · Jobs: 4', $text);
        $this->assertStringNotContainsString("*Ace Fireplace Services*\n", $text);

        // The parent's rule (its sub-companies in it) reads ACE's sheet under ACE's name (audit, 2026-11-10).
        $parent = $this->inTenant($this->t['org'], fn () => app(DailyTexts::class)->render('marketing_ppc', $this->t['org'], CarbonImmutable::parse('2026-10-20'),
            [$this->t['franchise']->id, $ace->id]));
        $this->assertStringContainsString("*Ace Fireplace Services*\n*Data date: Sat, Oct 17*", $parent);
    }

    /** Shoval, 2026-11-09: a sheet's areas in the location order too, a city by its state's codes ("IL, Chicago"). */
    public function test_marketing_ppc_lists_its_areas_in_the_location_order(): void
    {
        $this->inTenant($this->t['org'], fn () => DB::table('business_list_items')->insert(array_map(fn (array $r) => ['id' => (string) Str::uuid(),
            'organization_id' => $this->t['org']->id, 'list_key' => 'reports.locations', 'item_key' => $r[0], 'value' => json_encode(['branch' => $r[1], 'code' => $r[2]]),
            'active' => true, 'sort' => $r[3], 'created_at' => now(), 'updated_at' => now()], [
                ['NJ', 'New Jersey', 'NJ', 0], ['OH', 'Ohio', 'OH, Cleveland, Columbus', 1], ['IL', 'Illinois', 'IL, Chicago', 2]])));
        $this->airtableTable($this->t, null, 'marketing', array_map(fn (string $area) => ['DATE' => '2026-10-17', 'Service Area' => $area, 'COST' => 10, 'JOBS' => 1],
            ['Chicago', 'Austin', 'Columbus', 'New Jersey', 'Cleveland']));

        preg_match_all('/^\*([A-Za-z ]+)\*$/m', $this->text('marketing_ppc'), $m);

        $this->assertSame(['New Jersey', 'Cleveland', 'Columbus', 'Chicago', 'Austin'], $m[1]);
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
