<?php

namespace Tests\Feature\Reports;

use App\Models\AirtableRecord;
use App\Models\ReportGoal;
use App\Models\ReportSource;
use App\Support\Reports\Texts\AirtableSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The weekly report's Airtable sections (A1 Leads takeover, 2026-10-16). */
class AirtableSectionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private string $org;

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        $this->org = (string) $this->tenant['org']->id;
        // This tenant's mapping (report_sources): NJ's repair table, a shared
        // refunds table filtered to NJ, and the office expenses.
        $this->inTenant($this->tenant['org'], function () {
            ReportSource::query()->create(['base_id' => 'appzD9I7nNkALmw4l', 'table_id' => 'tbliTYwmQl2KXzoxh', 'role' => 'repair', 'company' => 'NJ']);
            foreach (['NJ', 'CT'] as $code) {
                ReportSource::query()->create(['base_id' => 'appe1vSSRRvKToShq', 'table_id' => 'tblxCKagahNfmjaSQ', 'role' => 'refunds',
                    'company' => $code, 'filter_field' => 'Company Name', 'filter_value' => 'A1 CHIMNEY '.$code]);
            }
            ReportSource::query()->create(['base_id' => 'app6ZZgY9nHX8mMih', 'table_id' => 'tblmV1sk6qs4QbUWU', 'role' => 'hq_expenses']);
        });
    }

    public function test_a_repair_team_costs_and_shares_to_the_cent(): void
    {
        $this->row('appzD9I7nNkALmw4l', 'tbliTYwmQl2KXzoxh', 'NJ', ['Date' => '2026-10-11', 'Team' => 'John', 'Record type' => 'Weekly Summary',
            'Employee Cost' => 1000.10, 'Helper Cost' => 200, 'Gas Cost' => 50.05, 'Ave Operation Cost' => 100, 'Total Hours ' => 40]);
        $this->row('appzD9I7nNkALmw4l', 'tbliTYwmQl2KXzoxh', 'NJ', ['Date' => '2026-10-08', 'Team' => 'John', 'Record type' => 'Job',
            'Job Amount' => 5000, 'Parts Cost' => 149.85, 'Total Tech Share' => 600, 'External Company Share' => 300, 'Total Contracting Profit' => 200]);
        // Outside the week: not counted.
        $this->row('appzD9I7nNkALmw4l', 'tbliTYwmQl2KXzoxh', 'NJ', ['Date' => '2026-10-01', 'Team' => 'John', 'Record type' => 'Job', 'Job Amount' => 9999]);

        $this->inTenant($this->tenant['org'], fn () => ReportGoal::query()->create(['company' => null, 'key' => 'repair_cost_from_gross', 'value' => 0.05]));
        $html = app(AirtableSections::class)->repairTeams($this->org, 'NJ', '2026-10-05', '2026-10-11');

        // 1000.10 + 200 + 100 + 50.05 + 149.85 = 1500.00; company = 1500 - 600 - 300 - 200 = 400.
        $this->assertStringContainsString('<td><b>Total cost</b></td><td><b>$1,500.00</b></td>', $html);
        $this->assertStringContainsString('<td>Company share</td><td>$400.00 · 26.7%</td>', $html);
        $this->assertStringContainsString('<td>Total jobs amount</td><td>$5,000.00</td>', $html);
        // 400 / 5000 = 8% of gross, over the 5% goal.
        $this->assertStringContainsString('#DC2626;font-weight:700">8.0%', $html);
    }

    public function test_refunds_are_the_branchs_own_in_the_dates(): void
    {
        $this->row('appe1vSSRRvKToShq', 'tblxCKagahNfmjaSQ', 'Refunds', ['Date' => '2026-10-06', 'Company Name' => 'A1 CHIMNEY NJ',
            'Job Number' => '901', 'Refund Amount' => 100.10, 'Total Jobs' => 500, 'Reason' => 'Late', 'Technician Name' => 'Ann', 'Open/Close Job' => 'Closed']);
        $this->row('appe1vSSRRvKToShq', 'tblxCKagahNfmjaSQ', 'Refunds', ['Date' => '2026-10-06', 'Company Name' => 'A1 CHIMNEY CT', 'Refund Amount' => 7]);
        $this->row('appe1vSSRRvKToShq', 'tblxCKagahNfmjaSQ', 'Refunds', ['Date' => '2026-09-01', 'Company Name' => 'A1 CHIMNEY NJ', 'Refund Amount' => 8]);

        $html = app(AirtableSections::class)->refunds($this->org, 'NJ', '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>901</td><td>Late</td><td>$100.10</td><td>Partial</td><td>Closed</td><td>Ann</td>', $html);
        $this->assertStringContainsString('<b>$100.10</b>', $html, 'the total is the one NJ row');
        $this->assertStringContainsString('<h3>Disputes</h3><p class="na">None in these dates.</p>', $html);
    }

    public function test_hq_expenses_merge_by_name_and_skip_airtables_total_row(): void
    {
        foreach ([['Parking', 10.10], ['parking', 5.05], ['Total', 999], ['Rent', 1200]] as [$name, $cost]) {
            $this->row('app6ZZgY9nHX8mMih', 'tblmV1sk6qs4QbUWU', 'Weekly HQ Expenses', ['Date' => '2026-10-11', 'Category Name' => $name, 'Cost' => $cost]);
        }

        $html = app(AirtableSections::class)->hqExpenses($this->org, '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>Parking</td><td>$15.15</td>', $html);
        $this->assertStringContainsString('<b>$1,215.15</b>', $html);
        $this->assertStringNotContainsString('999', $html);
    }

    public function test_goals_are_settings_per_company_and_unset_colours_nothing(): void
    {
        $html = fn () => app(AirtableSections::class)->repairTeams($this->org, 'NJ', '2026-10-05', '2026-10-11');
        $this->row('appzD9I7nNkALmw4l', 'tbliTYwmQl2KXzoxh', 'NJ', ['Date' => '2026-10-08', 'Team' => 'Kris', 'Record type' => 'Job', 'Job Amount' => 100, 'Parts Cost' => 50]);
        $this->assertStringNotContainsString('#DC2626', $html(), 'no goal, no red');

        $admin = $this->signedInAs($this->tenant['admin']);
        $admin->putJson('/api/v1/reports/goals', ['goals' => [['company' => 'NJ', 'key' => 'repair_cost_from_gross', 'value' => 0.05]]])->assertOk()
            ->assertJsonPath('data.goals.0.company', 'NJ');
        $this->assertStringContainsString('#DC2626;font-weight:700">50.0%', $html());

        $admin->putJson('/api/v1/reports/goals', ['goals' => [['company' => 'NJ', 'key' => 'invented', 'value' => 1]]])->assertUnprocessable();
        $viewer = $this->userWithRole($this->tenant, 'Viewer', ['reports.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/reports/goals')->assertOk();
        $this->signedInAs($viewer)->putJson('/api/v1/reports/goals', ['goals' => [['company' => 'NJ', 'key' => 'avg_sale', 'value' => 1]]])->assertForbidden();
    }

    public function test_another_tenants_field_names_map_onto_the_same_section(): void
    {
        $this->inTenant($this->tenant['org'], fn () => ReportSource::query()->create(['base_id' => 'appOther00000000a', 'table_id' => 'tblOther00000000a',
            'role' => 'refunds', 'company' => 'ZZ', 'fields' => ['amount' => 'Money back', 'date' => 'When', 'job' => 'Ticket']]));
        $this->row('appOther00000000a', 'tblOther00000000a', 'Mine', ['When' => '2026-10-07', 'Money back' => 12.34, 'Ticket' => 'T-1']);

        $html = app(AirtableSections::class)->refunds($this->org, 'ZZ', '2026-10-05', '2026-10-11');

        $this->assertStringContainsString('<td>T-1</td>', $html);
        $this->assertStringContainsString('<b>$12.34</b>', $html);
    }

    private function row(string $base, string $table, string $name, array $fields): void
    {
        $this->inTenant($this->tenant['org'], fn () => AirtableRecord::query()->create(['organization_id' => $this->org, 'base_id' => $base, 'table_id' => $table,
            'table_name' => $name, 'record_id' => 'rec'.uniqid(), 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now()]));
    }
}
