<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\ReportGoal;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Rodrigo's weekly and monthly PDF, core sections (Jose: "Ship core, switch now"). */
class PeriodReportTest extends TestCase
{
    use BuildsTenants;
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

        $this->fakeAirtable([
            ['Week' => '21 Sep - 27 Sep', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '21 Sep - 27 Sep', 'Marketing' => 'Ads', 'Marketing Amount' => 250],
        ]);

        // Goals are Settings (2026-10-16): Connecticut's average sale and every company's cancel rate.
        $this->inTenant($t['org'], function () {
            ReportGoal::query()->create(['company' => 'CT', 'key' => 'avg_sale', 'value' => 2500]);
            ReportGoal::query()->create(['company' => null, 'key' => 'cancel_rate', 'value' => 0.20]);
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

    public function test_a_month_with_a_week_not_entered_has_no_totals(): void
    {
        [, $f] = $this->connecticut();

        $this->fakeAirtable([
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

    private function fakeAirtable(array $records): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/bases/*' => Http::response(['tables' => [['id' => 'tbl1', 'name' => 'CT']]]),
            'api.airtable.com/*' => Http::response(['records' => array_map(fn ($f) => ['fields' => $f], $records)]),
        ]);
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
