<?php

namespace Tests\Feature\Financials;

use App\Models\Customer;
use App\Models\FinancialExpense;
use App\Models\FinancialMarketMap;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\WorkizJob;
use App\Support\Ai\AppAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Financials › Markets: expenses per market by week and category, closing
 * rate (Reports' leads to jobs), cost per closed job, inside the current view,
 * behind the tab permission; and the staff Q&A can read it.
 */
class FinancialMarketsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_expenses_closing_rate_and_cost_per_closed_job_per_market(): void
    {
        $t = $this->provisionTenant('Alpha');
        $miami = $this->extraFranchise($t, 'Miami');
        $this->inTenant($t['org'], function () use ($t, $miami) {
            $this->map('class', '100', $miami);
            $this->map('department', '5', $t['franchise']);
            // Location wins over class: this line is Alpha F1's.
            $this->expense('1', '2026-11-03', 10000, 'Advertising', class: '100', dept: '5');
            $this->expense('2', '2026-11-04', 5000, 'Advertising', class: '100');
            $this->expense('3', '2026-11-11', 2500, 'Fuel', class: '100');
            $this->expense('4', '2026-11-12', -500, 'Fuel', class: '100'); // a refund
            $this->expense('5', '2026-11-12', 700, 'Fuel'); // no class, no location
            $this->expense('6', '2026-10-01', 99999, 'Fuel', class: '100'); // outside the dates

            // Miami: 4 leads, 1 became a job → 25%. Test leads do not count.
            $customer = Customer::factory()->create(['owning_franchise_id' => $miami->id]);
            $job = Job::factory()->create(['customer_id' => $customer->id, 'franchise_id' => $miami->id]);
            foreach ([[$job->id, false], [null, false], [null, false], [null, false], [null, true]] as $i => [$jobId, $test]) {
                Lead::create(['organization_id' => $t['org']->id, 'franchise_id' => $miami->id, 'source' => 'website', 'remote_id' => "l{$i}",
                    'job_id' => $jobId, 'is_test' => $test, 'received_at' => '2026-11-05 15:00:00']);
            }
            // Miami: 2 jobs done in the dates, one done before, one open.
            foreach ([['Done', '2026-11-06 10:00:00'], ['Done', '2026-11-13 10:00:00'], ['Done', '2026-10-01 10:00:00'], ['Pending', null]] as [$status, $closed]) {
                WorkizJob::query()->create(['franchise_id' => $miami->id, 'remote_id' => 'J'.bin2hex(random_bytes(3)), 'status' => $status,
                    'closed_at' => $closed, 'payload' => [], 'synced_at' => now()]);
            }
        });

        $data = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/financials/markets?from=2026-11-02&to=2026-11-15')->assertOk()->json('data');
        $markets = collect($data['markets'])->keyBy('name');

        $m = $markets['Miami'];
        $this->assertSame(7000, $m['expenses_cents']);
        $this->assertSame([['category' => 'Advertising', 'cents' => 5000], ['category' => 'Fuel', 'cents' => 2000]], $m['by_category']);
        $this->assertSame(['2026-11-02', '2026-11-09'], array_column($m['weeks'], 'week_start'), 'weeks start Monday');
        $this->assertSame([5000, 2000], array_column($m['weeks'], 'cents'));
        $this->assertEquals([4, 1, 25.0], [$m['leads'], $m['leads_won'], $m['closing_rate']]);
        $this->assertSame(2, $m['closed_jobs']);
        $this->assertSame(3500, $m['cost_per_closed_job_cents']);

        $this->assertSame(10000, $markets['Alpha F1']['expenses_cents']);
        $this->assertNull($markets['Alpha F1']['closing_rate'], 'no leads is no rate, not 0%');
        $this->assertNull($markets['Alpha F1']['cost_per_closed_job_cents']);
        $this->assertSame(700, $markets['Unassigned']['expenses_cents']);
        $this->assertSame(17700, $data['total_expenses_cents']);
        $this->assertEquals(25.0, $data['average_closing_rate']);
        $this->assertFalse($data['quickbooks']['connected']);
    }

    public function test_a_branch_view_sees_its_own_market_only_and_no_unassigned(): void
    {
        $t = $this->provisionTenant('Alpha');
        $miami = $this->extraFranchise($t, 'Miami');
        $this->inTenant($t['org'], function () use ($t, $miami) {
            $this->map('class', '100', $miami);
            $this->map('class', '101', $t['franchise']);
            $this->expense('1', '2026-11-03', 100, 'Fuel', class: '100');
            $this->expense('2', '2026-11-03', 200, 'Fuel', class: '101');
            $this->expense('3', '2026-11-03', 300, 'Fuel');
        });
        $manager = $this->userWithRole($t, 'Miami manager', ['financials.view', 'financials.markets.view', 'financials.edit'], onlyAt: $miami);

        $data = $this->signedInAs($manager)->getJson('/api/v1/financials/markets?from=2026-11-02&to=2026-11-08')->assertOk()->json('data');
        $this->assertSame(['Miami'], array_column($data['markets'], 'name'));
        $this->assertSame(100, $data['total_expenses_cents']);

        // Nor can it move another branch's class.
        $other = $this->inTenant($t['org'], fn () => FinancialMarketMap::query()->where('remote_id', '101')->sole());
        $this->signedInAs($manager)->patchJson('/api/v1/financials/markets/maps/'.$other->id, ['franchise_id' => $miami->id])->assertForbidden();
        $this->assertSame($t['franchise']->id, $this->inTenant($t['org'], fn () => $other->fresh()->franchise_id));
    }

    public function test_the_tab_and_the_mapping_are_gated(): void
    {
        $t = $this->provisionTenant('Alpha');
        $map = $this->inTenant($t['org'], fn () => $this->map('class', '100', null));

        $this->signedInAs($this->userWithRole($t, 'Clerk', ['financials.view']))
            ->getJson('/api/v1/financials/markets')->assertForbidden();

        $reader = $this->userWithRole($t, 'Reader', ['financials.view', 'financials.markets.view']);
        $this->signedInAs($reader)->getJson('/api/v1/financials/markets')->assertOk()
            ->assertJsonPath('data.from', now()->startOfWeek()->subWeeks(4)->toDateString());
        $this->signedInAs($reader)->getJson('/api/v1/financials/markets/maps')->assertOk()->assertJsonPath('data.maps.0.dimension_label', 'Class');
        $this->signedInAs($reader)->patchJson('/api/v1/financials/markets/maps/'.$map->id, ['franchise_id' => $t['franchise']->id])->assertForbidden();

        $this->signedInAs($t['admin'])->patchJson('/api/v1/financials/markets/maps/'.$map->id, ['franchise_id' => $t['franchise']->id])
            ->assertOk()->assertJsonPath('data.mapped_by', 'person');
        $this->assertTrue($this->inTenant($t['org'], fn () => \App\Models\AuditEvent::query()->where('action', 'financials.market_mapped')->exists()));
    }

    public function test_the_staff_question_bot_is_offered_the_markets_report(): void
    {
        $this->assertMatchesRegularExpression('#^financials/markets \[[^\]]*from[^\]]*to#m', AppAsk::catalogue());
    }

    private function map(string $dimension, string $remote, ?Franchise $franchise): FinancialMarketMap
    {
        return FinancialMarketMap::query()->create(['realm_id' => '46', 'dimension' => $dimension, 'remote_id' => $remote,
            'name' => $dimension.$remote, 'franchise_id' => $franchise?->id, 'mapped_by' => 'name']);
    }

    private function expense(string $txn, string $date, int $cents, string $account, ?string $class = null, ?string $dept = null): void
    {
        FinancialExpense::query()->create(['realm_id' => '46', 'txn_type' => 'Purchase', 'txn_id' => $txn, 'line_id' => '1',
            'txn_date' => $date, 'amount_cents' => $cents, 'account_name' => $account, 'class_id' => $class, 'department_id' => $dept]);
    }
}
