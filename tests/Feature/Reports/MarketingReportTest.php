<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\MarketingReport;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MapsAirtableTables;
use Tests\TestCase;

/** Jose, 2026-10-12: ad cost against jobs, sales and profit per source. */
class MarketingReportTest extends TestCase
{
    use BuildsTenants;
    use MapsAirtableTables;
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
        $this->airtableTable($t, (string) $f->id, 'pnl', [
            ['Week' => '21 Sep - 27 Sep', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '21 Sep - 27 Sep', 'Marketing' => 'Ads', 'Marketing Amount' => 200],
            ['Week' => '21 Sep - 27 Sep', 'Payroll' => 'Techs', 'Payroll Amount' => 500],
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
        $this->assertNull($ls['profit_missing']);

        // A part week the P&L has no figures for: no profit, and why.
        $part = collect(app(MarketingReport::class)->rows(Franchise::query()->withoutGlobalScopes()->find($f->id), '2026-09-22', '2026-09-23'))->keyBy('source');
        $this->assertNull($part['LS Ads']['profit_minor']);
        $this->assertNotNull($part['LS Ads']['profit_missing']);

        // PPC is paid for but had nothing and no cost: left out.
        $this->assertFalse($by->has('PPC'));
    }

    /** Jose, 2026-11-04: which job source an ad channel pays for is the business's list, not "PPC" in code. */
    public function test_a_paid_source_set_on_the_list_carries_that_channels_cost(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->signedInAs($t['admin'])->postJson('/api/v1/settings/lists/reports.paid_sources/items',
            ['value' => ['source' => 'Google Search', 'channel' => 'PPC']])->assertSuccessful();
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/settings/lists/reports.paid_sources/items/PPC')->assertSuccessful();

        $rows = $this->inTenant($t['org'], fn () => app(MarketingReport::class)
            ->withCost(fn () => ['ppc' => ['cost_minor' => 12300, 'clicks' => 9, 'conversions' => 1]])
            ->rows(Franchise::query()->find($f->id), '2026-09-21', '2026-09-27'));
        $by = collect($rows)->keyBy('source');

        $this->assertSame(12300, $by['Google Search']['cost_minor']);
        $this->assertFalse($by->has('PPC'));
    }

    /**
     * Jose, 2026-11-16: Angi Sweep and Ace showed no cost; their own companies' accounts pay for them.
     * "AngiSweep - CT" is Connecticut's by its code; one that names no branch is shared by jobs created.
     */
    public function test_a_sub_company_source_takes_its_accounts_by_branch_code_and_a_share_of_the_rest(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $nj = $this->extraFranchise($t, 'New Jersey');
        $as = $this->signedInAs($t['admin']);
        $as->postJson('/api/v1/settings/lists/reports.paid_sources/items', ['value' => ['source' => 'Angi Sweep', 'channel' => '', 'company' => 'AngiSweep']])->assertSuccessful();
        $as->postJson('/api/v1/settings/lists/reports.locations/items', ['value' => ['branch' => 'Connecticut', 'code' => 'CT']])->assertSuccessful();
        $as->postJson('/api/v1/settings/lists/reports.locations/items', ['value' => ['branch' => 'New Jersey', 'code' => 'NJ']])->assertSuccessful();

        $this->inTenant($t['org'], function () use ($t, $f, $nj) {
            $f->update(['name' => 'Connecticut']);
            $angi = Company::create(['organization_id' => $t['org']->id, 'name' => 'AngiSweep']);
            foreach (['AngiSweep - CT', 'AngiSweep - NJ', 'Angi Home Care LLC'] as $i => $name) {
                $c = new SocialConnection;
                $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $angi->id,
                    'provider' => SocialProvider::GoogleAds->value, 'remote_id' => '10000000'.$i, 'display_name' => $name, 'is_manager' => false]);
                $c->connect(['refresh_token' => 'r']);
                $c->save();
            }
            // Three Angi Sweep jobs in Connecticut, one in New Jersey.
            foreach ([$f, $f, $f, $nj] as $branch) {
                $this->job($branch, ['job_source' => 'Angi Sweep', 'status' => 'Submitted', 'booked_at' => '2026-09-22 09:00:00']);
            }
        });

        $spend = ['AngiSweep - CT' => [10000, 20, 2.0], 'AngiSweep - NJ' => [5000, 9, 1.0], 'Angi Home Care LLC' => [8000, 40, 4.0]];
        $rows = $this->inTenant($t['org'], fn () => app(MarketingReport::class)
            ->withCost(fn () => null)
            ->withAccountsCost(function ($accounts) use ($spend) {
                $sum = ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0];
                foreach ($accounts as $a) {
                    [$cost, $clicks, $conv] = $spend[$a->display_name];
                    $sum = ['cost_minor' => $sum['cost_minor'] + $cost, 'clicks' => $sum['clicks'] + $clicks, 'conversions' => $sum['conversions'] + $conv];
                }

                return ['lsa' => $sum, 'ppc' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0], 'accounts' => count($accounts), 'refused' => []];
            })
            ->rows(Franchise::query()->find($f->id), '2026-09-21', '2026-09-27'));
        $angi = collect($rows)->keyBy('source')['Angi Sweep'];

        // CT's own account, and three quarters of the one that names no branch; never NJ's.
        $this->assertSame([16000, 50, 5.0, 3], [$angi['cost_minor'], $angi['clicks'], $angi['conversions'], $angi['jobs_created']]);
        $this->assertStringContainsString('75% of Angi Home Care LLC', $angi['cost_note']);
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
