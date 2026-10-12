<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\MarketingReport;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: ad cost against jobs, sales and profit per source. */
class MarketingReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_cost_jobs_sales_and_estimated_profit_per_source(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];

        $this->inTenant($t['org'], function () use ($t, $f) {
            $f->update(['name' => 'Connecticut']);
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $t['org']->id, 'provider' => SocialProvider::Airtable->value,
                'remote_id' => 'appPNL', 'display_name' => 'P&L', 'handle' => 'appPNL',
            ]);
            $c->connect(['refresh_token' => 'pat', 'api_token' => 'pat', 'base_id' => 'appPNL']);
            $c->save();

            // Two LS Ads jobs booked in the week, one done for $1,000.
            $this->job($f, ['job_source' => 'LS Ads', 'status' => 'Submitted', 'booked_at' => '2026-09-22 09:00:00']);
            $this->job($f, ['job_source' => 'LS Ads', 'status' => 'Done', 'booked_at' => '2026-09-22 10:00:00',
                'last_status_update' => '2026-09-24 10:00:00', 'payload' => ['JobTotalPrice' => 1000]]);
            $this->job($f, ['job_source' => 'GMB /SEO', 'status' => 'Done', 'booked_at' => '2026-09-23 10:00:00',
                'last_status_update' => '2026-09-25 10:00:00', 'payload' => ['JobTotalPrice' => 500]]);
        });

        // Income 1,000, marketing 200, net 300: 50% before marketing.
        Http::fake([
            'api.airtable.com/v0/meta/bases/*' => Http::response(['tables' => [['id' => 'tbl1', 'name' => 'CT']]]),
            'api.airtable.com/*' => Http::response(['records' => array_map(fn ($r) => ['fields' => $r], [
                ['Week' => '21 Sep - 27 Sep', 'Income' => 'Cash', 'Income Amount' => 1000],
                ['Week' => '21 Sep - 27 Sep', 'Marketing' => 'Ads', 'Marketing Amount' => 200],
                ['Week' => '21 Sep - 27 Sep', 'Payroll' => 'Techs', 'Payroll Amount' => 500],
            ])]),
        ]);

        $rows = app(MarketingReport::class)
            ->withCost(fn () => ['lsa' => ['cost_minor' => 30000, 'clicks' => 40, 'conversions' => 5]])
            ->rows(Franchise::query()->withoutGlobalScopes()->find($f->id), '2026-09-21', '2026-09-27');
        $by = collect($rows)->keyBy('source');

        $ls = $by['LS Ads'];
        $this->assertSame([40, 5, 30000, 2, 15000, 1, 100000, 50000, 20000], [
            $ls['clicks'], $ls['conversions'], $ls['cost_minor'], $ls['jobs_created'], $ls['cost_per_job_minor'],
            $ls['done_jobs'], $ls['gross_minor'], $ls['profit_minor'], $ls['net_profit_minor'],
        ]);

        // No ad cost: null, not zero; profit is still the margin's.
        $this->assertNull($by['GMB /SEO']['cost_minor']);
        $this->assertSame(25000, $by['GMB /SEO']['net_profit_minor']);

        // PPC is paid for but had nothing and no cost: left out.
        $this->assertFalse($by->has('PPC'));
    }

    public function test_it_needs_the_profit_and_loss_permission(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'technician'))
            ->getJson('/api/v1/reports/marketing?from=2026-09-21&to=2026-09-27')
            ->assertForbidden();

        // The tab alone is not enough: profit is the P&L's margin.
        $this->signedInAs($this->userWithRole($t, 'marketer', ['reports.marketing.view']))
            ->getJson('/api/v1/reports/marketing?from=2026-09-21&to=2026-09-27')
            ->assertForbidden();

        $this->signedInAs($this->userWithRole($t, 'accountant', ['profit_loss.view', 'reports.marketing.view']))
            ->getJson('/api/v1/reports/marketing?from=2026-09-21&to=2026-09-27')
            ->assertOk()
            ->assertJsonPath('data.rows', []);
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
}
