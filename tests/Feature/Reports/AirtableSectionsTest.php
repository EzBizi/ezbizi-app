<?php

namespace Tests\Feature\Reports;

use App\Models\FinancialBase;
use App\Models\FinancialField;
use App\Models\FinancialRecord;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Models\ReportGoal;
use App\Support\Reports\Texts\AirtableSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MapsAirtableTables;
use Tests\TestCase;

/**
 * The weekly report's Airtable sections, read through the tenant's own
 * mapping (Financials' sources) over the hourly copy (2026-10-16).
 */
class AirtableSectionsTest extends TestCase
{
    use BuildsTenants;
    use MapsAirtableTables;
    use RefreshDatabase;

    private array $t;

    private string $org;

    private string $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->org = (string) $this->t['org']->id;
        $this->branch = (string) $this->t['franchise']->id;
    }

    public function test_a_repair_team_costs_and_shares_to_the_cent(): void
    {
        $this->airtableTable($this->t, $this->branch, 'repair', [
            ['Date' => '2026-10-11', 'Team' => 'John', 'Record type' => 'Weekly Summary',
                'Employee Cost' => 1000.10, 'Helper Cost' => 200, 'Gas Cost' => 50.05, 'Ave Operation Cost' => 100, 'Total Hours ' => 40],
            ['Date' => '2026-10-08', 'Team' => 'John', 'Record type' => 'Job',
                'Job Amount' => 5000, 'Parts Cost' => 149.85, 'Total Tech Share' => 600, 'External Company Share' => 300, 'Total Contracting Profit' => 200],
            // Outside the week: not counted.
            ['Date' => '2026-10-01', 'Team' => 'John', 'Record type' => 'Job', 'Job Amount' => 9999],
        ], ['markers' => ['summary' => 'Weekly Summary']]);
        $this->inTenant($this->t['org'], fn () => ReportGoal::query()->create(['franchise_id' => null, 'key' => 'repair_cost_from_gross', 'value' => 0.05]));

        $html = app(AirtableSections::class)->repairTeams($this->org, $this->branch, '2026-10-05', '2026-10-11');

        // 1000.10 + 200 + 100 + 50.05 + 149.85 = 1500.00; company = 1500 - 600 - 300 - 200 = 400.
        $this->assertStringContainsString('<td><b>Total cost</b></td><td><b>$1,500.00</b></td>', $html);
        $this->assertStringContainsString('<td>Company share</td><td>$400.00 · 26.7%</td>', $html);
        $this->assertStringContainsString('<td>Total jobs amount</td><td>$5,000.00</td>', $html);
        // 400 / 5000 = 8% of gross, over the 5% goal.
        $this->assertStringContainsString('#DC2626;font-weight:700">8.0%', $html);
    }

    public function test_a_shared_refunds_table_gives_each_branch_its_own_rows(): void
    {
        $rows = [
            ['Date' => '2026-10-06', 'Company Name' => 'NORTH', 'Job Number' => '901', 'Refund Amount' => 100.10, 'Total Jobs' => 500,
                'Reason' => 'Late', 'Technician Name' => 'Ann', 'Open/Close Job' => 'Closed'],
            ['Date' => '2026-10-06', 'Company Name' => 'SOUTH', 'Refund Amount' => 7],
            ['Date' => '2026-09-01', 'Company Name' => 'NORTH', 'Refund Amount' => 8],
        ];
        $source = ['base_id' => 'appShared00000001', 'table_id' => 'tblShared00000001', 'filter_field' => 'Company Name'];
        $this->airtableTable($this->t, $this->branch, 'refunds', $rows, $source + ['filter_value' => 'NORTH']);
        $south = (string) $this->extraFranchise($this->t, 'South')->id;
        $this->airtableTable($this->t, $south, 'refunds', [], $source + ['filter_value' => 'SOUTH']);

        $html = app(AirtableSections::class)->refunds($this->org, $this->branch, '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>901</td><td>Late</td><td>$100.10</td><td>Partial</td><td>Closed</td><td>Ann</td>', $html);
        $this->assertStringContainsString('<b>$100.10</b>', $html, 'the total is the one row of this branch');
        $this->assertStringContainsString('<h3>Disputes</h3><p class="na">No table is mapped', $html);
        $this->assertStringContainsString('<b>$7.00</b>', app(AirtableSections::class)->refunds($this->org, $south, '2026-10-05', '2026-10-11'));
    }

    public function test_another_tenants_column_names_map_onto_the_same_section(): void
    {
        $this->airtableTable($this->t, $this->branch, 'refunds', [['When' => '2026-10-07', 'Money back' => 12.34, 'Ticket' => 'T-1']],
            ['field_map' => ['amount' => 'Money back', 'date' => 'When', 'job_number' => 'Ticket']]);

        $html = app(AirtableSections::class)->refunds($this->org, $this->branch, '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>T-1</td>', $html);
        $this->assertStringContainsString('<b>$12.34</b>', $html);
    }

    public function test_a_per_branch_table_not_yet_given_a_branch_reads_as_not_mapped(): void
    {
        // A source with its table but no place yet.
        $this->airtableTable($this->t, null, 'repair', [['Date' => '2026-10-08', 'Team' => 'John', 'Job Amount' => 1]]);

        $this->assertStringContainsString('No repair team table is mapped',
            app(AirtableSections::class)->repairTeams($this->org, $this->branch, '2026-10-05', '2026-10-11'));
    }

    public function test_hq_expenses_merge_by_name_and_skip_the_marked_subtotal(): void
    {
        $this->airtableTable($this->t, null, 'hq_expenses', array_map(
            fn ($r) => ['Date' => '2026-10-11', 'Category Name' => $r[0], 'Cost' => $r[1]],
            [['Parking', 10.10], ['parking', 5.05], ['Total', 999], ['Rent', 1200]],
        ), ['markers' => ['skip' => 'Total']]);

        $html = app(AirtableSections::class)->hqExpenses($this->org, '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>Parking</td><td>$15.15</td>', $html);
        $this->assertStringContainsString('<b>$1,215.15</b>', $html);
        $this->assertStringNotContainsString('999', $html);
    }

    public function test_goals_are_settings_per_branch_and_unset_colours_nothing(): void
    {
        $this->airtableTable($this->t, $this->branch, 'repair', [['Date' => '2026-10-08', 'Team' => 'Kris', 'Job Amount' => 100, 'Parts Cost' => 50]]);
        $html = fn () => app(AirtableSections::class)->repairTeams($this->org, $this->branch, '2026-10-05', '2026-10-11');
        $this->assertStringNotContainsString('#DC2626', $html(), 'no goal, no red');

        $admin = $this->signedInAs($this->t['admin']);
        $admin->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => $this->branch, 'key' => 'repair_cost_from_gross', 'value' => 0.05]]])
            ->assertOk()->assertJsonPath('data.goals.0.franchise_id', $this->branch);
        $this->assertStringContainsString('#DC2626;font-weight:700">50.0%', $html());

        $admin->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => $this->branch, 'key' => 'invented', 'value' => 1]]])->assertUnprocessable();
        $viewer = $this->userWithRole($this->t, 'Viewer', ['reports.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/reports/goals')->assertOk();
        $this->signedInAs($viewer)->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => null, 'key' => 'avg_sale', 'value' => 1]]])->assertForbidden();
        $this->assertSame(1, FinancialSource::withoutGlobalScopes()->count(), 'goals do not touch the mapping');
    }

    /** Roles & Permissions, 2026-10-16: goals follow the reader's view. */
    public function test_a_branch_manager_sets_and_sees_only_their_own_branchs_goals(): void
    {
        $sibling = (string) $this->extraFranchise($this->t, 'Sibling')->id;
        $this->inTenant($this->t['org'], function () use ($sibling) {
            ReportGoal::query()->create(['franchise_id' => $sibling, 'key' => 'avg_sale', 'value' => 900]);
            ReportGoal::query()->create(['franchise_id' => null, 'key' => 'avg_sale', 'value' => 800]);
        });
        $manager = $this->userWithRole($this->t, 'Branch Manager', ['reports.view', 'reports.settings'], onlyAt: $this->t['franchise']);
        $as = $this->signedInAs($manager);

        $as->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => $this->branch, 'key' => 'avg_sale', 'value' => 1000]]])->assertOk();
        $as->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => $sibling, 'key' => 'avg_sale', 'value' => 1]]])->assertForbidden();
        $as->putJson('/api/v1/reports/goals', ['goals' => [['franchise_id' => null, 'key' => 'avg_sale', 'value' => 1]]])->assertForbidden();

        $seen = collect($as->getJson('/api/v1/reports/goals')->assertOk()->json('data.goals'));
        $this->assertSame([$this->branch], $seen->pluck('franchise_id')->all(), 'neither the sibling nor the organization-wide row');
        $this->assertCount(3, collect($this->signedInAs($this->t['admin'])->getJson('/api/v1/reports/goals')->json('data.goals')));
    }

    /** Financials, 2026-10-21: a table the tenant cut over to EzBizi is read from there, not the stale copy. */
    public function test_a_cut_over_table_is_read_from_financials(): void
    {
        $this->airtableTable($this->t, null, 'hq_expenses', [['Date' => '2026-10-11', 'Category Name' => 'Rent', 'Cost' => 999]],
            ['base_id' => 'appCut00000000001', 'table_id' => 'tblCut00000000001']);
        $this->inTenant($this->t['org'], function () {
            $base = FinancialBase::query()->create(['name' => 'Books', 'colour' => '#000000']);
            $table = FinancialTable::query()->create(['base_id' => $base->id, 'name' => 'HQ', 'source_base_id' => 'appCut00000000001',
                'source_table_id' => 'tblCut00000000001', 'cut_over_at' => now()]);
            $f = fn ($name, $type) => FinancialField::query()->create(['table_id' => $table->id, 'name' => $name, 'source_name' => $name, 'type' => $type]);
            [$date, $name, $cost] = [$f('Date', 'date'), $f('Category Name', 'text'), $f('Cost', 'number')];
            FinancialRecord::query()->create(['table_id' => $table->id, 'values' => [$date->id => '2026-10-11', $name->id => 'Rent', $cost->id => 1200]]);
        });

        $html = app(AirtableSections::class)->hqExpenses($this->org, '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>Rent</td><td>$1,200.00</td>', $html);
        $this->assertStringNotContainsString('999', $html);
    }

    /** Takeover row 42: per job, total less parts, tech share and external share; summary rows left out. */
    public function test_job_profitability_per_job_as_pdf_or_excel(): void
    {
        $this->airtableTable($this->t, $this->branch, 'repair', [
            ['Date' => '2026-10-08', 'Team' => 'John', 'Record type' => 'Job', 'Job Number' => '4411',
                'Job Amount' => 5000, 'Parts Cost' => 149.85, 'Total Tech Share' => 600, 'External Company Share' => 300],
            ['Date' => '2026-10-11', 'Team' => 'John', 'Record type' => 'Weekly Summary', 'Employee Cost' => 1000],
        ], ['markers' => ['summary' => 'Weekly Summary']]);
        $as = $this->signedInAs($this->t['admin']);
        $q = "/api/v1/reports/period?report=job_profitability&franchise_id={$this->branch}&from=2026-10-05&to=2026-10-11";

        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $as->get($q.'&format=xlsx')->assertOk()->getContent());
        $rows = IOFactory::load($file)->getSheetByName('Jobs')->toArray(null, true, false);
        unlink($file);
        $this->assertCount(2, $rows, 'header and the one job, not the summary');
        $this->assertSame(['2026-10-08', 4411, 'John', 5000.0, 149.85, 600.0, 300.0, 3950.15, '79%'], array_slice($rows[1], 1));
        $this->assertStringStartsWith('%PDF', $as->get($q)->assertOk()->getContent());
    }
}
