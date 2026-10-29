<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\ReportGoal;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Reports\ReportJobs;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Reports\Xlsx;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MapsAirtableTables;
use Tests\TestCase;

/** Rodrigo's weekly and monthly PDF, core sections (Jose: "Ship core, switch now"). */
class PeriodReportTest extends TestCase
{
    use BuildsTenants;
    use MapsAirtableTables;
    use RefreshDatabase;

    public function test_a_week_reads_sources_techs_deposits_and_the_pnl(): void
    {
        [$t, $f] = $this->connecticut();

        $this->inTenant($t['org'], function () use ($f) {
            // Created in the week: two LS Ads (one done for 400, one open).
            $this->job($f, ['job_source' => 'LS Ads', 'status' => 'Submitted', 'booked_at' => '2026-09-22 09:00:00']);
            $this->job($f, ['job_source' => 'LS Ads', 'status' => 'Done', 'booked_at' => '2026-09-23 09:00:00',
                'last_status_update' => '2026-09-24 10:00:00', 'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann']], 'JobTotalPrice' => 400]]);
            // Booked before, canceled in the week: closed, not created.
            $this->job($f, ['job_source' => 'GMB', 'status' => 'Canceled', 'booked_at' => '2026-09-01 09:00:00',
                'last_status_update' => '2026-09-25 10:00:00', 'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann']]]]);

            // Two people Workiz's API cannot name collect money: each shown by id.
            $this->payment($f, 'USR-x', 100, '2026-09-26 12:00:00');
            $this->payment($f, 'USR-y', 50, '2026-09-27 12:00:00');
            $this->payment($f, 'USR-ann', 400, '2026-09-24 12:00:00');
        });

        $this->airtableTable($t, (string) $f->id, 'pnl', [
            ['Week' => '21 Sep - 27 Sep', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '21 Sep - 27 Sep', 'Marketing' => 'Ads', 'Marketing Amount' => 250],
        ]);

        // Goals are Settings (2026-10-16): Connecticut's average sale and every company's cancel rate.
        $this->inTenant($t['org'], function () use ($f) {
            ReportGoal::query()->create(['franchise_id' => $f->id, 'key' => 'avg_sale', 'value' => 2500]);
            ReportGoal::query()->create(['franchise_id' => null, 'key' => 'cancel_rate', 'value' => 0.20]);
        });
        $marked = app(PeriodReport::class)->html($f, '2026-09-21', '2026-09-27');

        // Goal colours (2026-10-16): Connecticut's average sale goal is $2,500 and
        // a cancel rate over 20% is red, so these three cells are marked.
        $red = '<span style="color:#DC2626;font-weight:700">';
        $this->assertStringContainsString('<td>'.$red.'$400.00</span></td>', $marked);
        $this->assertStringContainsString('<td>'.$red.'100.0%</span></td>', $marked);
        $this->assertStringContainsString('<td>'.$red.'50.0%</span></td>', $marked);
        $this->assertStringContainsString('<td>0.0%</td>', $marked, 'under the goal is not marked');
        $html = preg_replace('#</?span[^>]*>#', '', $marked);

        $this->assertStringContainsString('Alpha Co · Weekly Report · Sep 21 – Sep 27, 2026', $html);
        $this->assertStringContainsString($this->row('LS Ads', '2', '1', '1', '0', '0.0%', '$400.00', '$400.00'), $html);
        $this->assertStringContainsString($this->row('GMB', '0', '1', '100.0%', '$0.00', '—'), $html);
        $this->assertStringContainsString($this->row('Ann', '1', '1', '50.0%', '$400.00', '$400.00', '$400.00'), $html);
        $this->assertStringContainsString($this->row("Not in Workiz's API (USR-x)", '0', '0', '—', '$0.00', '—', '$100.00'), $html);
        $this->assertStringContainsString($this->row("Not in Workiz's API (USR-y)", '0', '0', '—', '$0.00', '—', '$50.00'), $html);
        $this->assertStringContainsString($this->row('Income', '$1,000.00', '100.0%'), $html);
        $this->assertStringContainsString($this->row('$1,000.00', '$250.00', '$750.00', '75.0%'), $html);

        $this->assertStringStartsWith('%PDF', app(PeriodReport::class)->forFranchise($f, '2026-09-21', '2026-09-27'));
    }

    /** Workiz (2026-10-29): a sub-contracted job is credited to its external company, as Workiz's Sources report does. */
    public function test_a_subcontracted_job_reports_its_external_company_as_the_source(): void
    {
        [$t, $f] = $this->connecticut();
        $sources = $this->inTenant($t['org'], function () use ($f) {
            $this->job($f, ['job_source' => 'GMB', 'external_company_id' => '7', 'status' => 'Submitted', 'booked_at' => '2026-09-22 09:00:00']);
            $this->job($f, ['job_source' => 'LS Ads', 'external_company_id' => '9', 'status' => 'Submitted', 'booked_at' => '2026-09-22 09:00:00']);
            WorkizRecord::query()->create(['franchise_id' => $f->id, 'type' => 'external_company', 'remote_id' => 'EC-7',
                'payload' => ['company_name' => ' MS Chimney '], 'synced_at' => now()]);

            return ReportJobs::jobs((string) $f->id)->pluck('job_source')->sort()->values()->all();
        });

        $this->assertSame(['LS Ads', 'MS Chimney'], $sources, 'no record for EC-9 yet: falls back to job_source');
    }

    /** 2026-10-16: one table stood for the branch until a date, another from it. */
    public function test_a_branch_reads_each_pnl_table_only_for_its_own_dates(): void
    {
        [$t, $f] = $this->connecticut();
        $week = fn (int $income, string $date) => [
            ['Date' => $date, 'Week' => '28 Sep - 4 Oct', 'Income' => 'Cash', 'Income Amount' => $income],
        ];
        // The old table still has rows after it stopped standing for the branch.
        $this->airtableTable($t, (string) $f->id, 'pnl', $week(999, '2026-10-04'), ['table_id' => 'tblOld00000000001', 'valid_until' => '2026-09-20']);
        $this->airtableTable($t, (string) $f->id, 'pnl', $week(1000, '2026-10-04'), ['table_id' => 'tblNew00000000001', 'valid_from' => '2026-09-21']);

        $html = app(PeriodReport::class)->html($f, '2026-09-28', '2026-10-04');

        // An income-only week is incomplete, so it carries no percentage.
        $this->assertStringContainsString($this->row('Income', '$1,000.00', '—'), $html);
        $this->assertStringNotContainsString('999', $html);
    }

    public function test_a_month_with_a_week_not_entered_has_no_totals(): void
    {
        [$t, $f] = $this->connecticut();

        $this->airtableTable($t, (string) $f->id, 'pnl', [
            ['Week' => '7 Sep - 13 Sep', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '7 Sep - 13 Sep', 'Marketing' => 'Ads', 'Marketing Amount' => 250],
        ]);

        $html = app(PeriodReport::class)->html($f, '2026-09-01', '2026-09-30');

        $this->assertStringContainsString('Alpha Co · Monthly Report · September 2026', $html);
        $this->assertStringContainsString($this->row('7 Sep - 13 Sep', '$1,000.00', '$250.00', '$750.00'), $html);
        $this->assertStringContainsString($this->row('—', '—', '—', '—'), $html);
        $this->assertStringContainsString('Week 14 Sep - 20 Sep is not in the Airtable P&amp;L yet.', $html);
    }

    /** @return array{0: array, 1: Franchise} */
    /** Takeover rows 35 and 50: any dates or a year, as PDF or Excel, inside the reader's view. */
    public function test_the_report_on_request_as_pdf_or_excel_and_yearly(): void
    {
        [$t, $f] = $this->connecticut();
        $this->inTenant($t['org'], fn () => $this->job($f, ['job_source' => 'LS Ads', 'status' => 'Done', 'booked_at' => '2026-03-02 09:00:00',
            'last_status_update' => '2026-03-03 10:00:00', 'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann']], 'JobTotalPrice' => 400]]));
        $as = $this->signedInAs($t['admin']);

        $pdf = $as->get("/api/v1/reports/period?franchise_id={$f->id}&from=2026-03-01&to=2026-03-31")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringStartsWith('%PDF', $as->get('/api/v1/reports/period?year=2026')->assertOk()->getContent(), 'the company, for the year');

        $xlsx = $as->get("/api/v1/reports/period?franchise_id={$f->id}&year=2026&format=xlsx")->assertOk()->getContent();
        $this->assertSame(['Alpha Co', 'LS Ads', 1.0, 1.0, 0.0, 0.0, 400.0], Xlsx::read($xlsx, 'Created')[1]);
        $this->assertSame('Ann', Xlsx::read($xlsx, 'Technicians')[1][1]);

        $sibling = $this->extraFranchise($t, 'Sibling');
        $manager = $this->userWithRole($t, 'Branch Manager', ['reports.view'], onlyAt: $f);
        $this->signedInAs($manager)->get("/api/v1/reports/period?franchise_id={$sibling->id}&year=2026")->assertForbidden();
        $this->signedInAs($manager)->get("/api/v1/reports/period?franchise_id={$f->id}&year=2026&format=xlsx")->assertForbidden();
    }

    private function connecticut(): array
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $f->update(['name' => 'Connecticut']);

            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $t['org']->id, 'provider' => SocialProvider::Airtable->value,
                'remote_id' => 'appPNL', 'display_name' => 'Profit And Loss', 'handle' => 'appPNL',
            ]);
            $c->connect(['refresh_token' => 'pat', 'api_token' => 'pat', 'base_id' => 'appPNL']);
            $c->save();
        });

        return [$t, $f->fresh()];
    }

    private function row(string ...$cells): string
    {
        return '<tr>'.implode('', array_map(fn ($c) => '<td>'.e($c).'</td>', $cells)).'</tr>';
    }

    private function job($franchise, array $attributes): void
    {
        WorkizJob::query()->create($attributes + [
            'franchise_id' => $franchise->id,
            'remote_id' => 'JOB-'.bin2hex(random_bytes(4)),
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function payment($franchise, string $by, float $amount, string $at): void
    {
        WorkizRecord::query()->create([
            'franchise_id' => $franchise->id, 'type' => 'payment', 'remote_id' => 'PAY-'.bin2hex(random_bytes(4)),
            'user_remote_id' => $by, 'amount' => $amount, 'starts_at' => $at, 'payload' => [], 'synced_at' => now(),
        ]);
    }
}
