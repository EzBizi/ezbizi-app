<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use App\Support\Ai\AppAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The weekly repair-team report, seeded with the shape and figures of A-1
 * Chimney NJ's sheet for 2026-10-12..18 (measured on production 2026-10-22),
 * against Jose's "Repair Team" report for that week.
 */
class RepairReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_week_matches_the_report_to_the_cent(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $n = 0;
            $row = function (array $fields) use ($t, &$n) {
                $fields += ['Week - Dates ' => '12 Oct - 18 Oct', 'Current Week' => 'Old'];
                AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appR', 'table_id' => 'tblNJ', 'table_name' => 'NJ',
                    'record_id' => 'r'.(++$n), 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now()]);
            };
            // [team, day, job amount, parts cost, company parts, tech share, total external share]
            $jobs = [
                ['Darren', '13', 5865, 150, 1500, 375, 750], ['Darren', '14', 4000, 110, 1200, 300, 600],
                ['Darren', '15', 6897, 10, 1700, 425, 850], ['Darren', '16', 3098, 450, 1450, 362.5, 725],
                ['John', '12', 4399, 110, 1200, 300, 600], ['John', '13', 2856, 10, 500, 125, 250], ['John', '14', 10589, 2872, 5172, 1293, 2586],
                ['Kris', '12', 4399, 110, 1200, 300, 600], ['Kris', '13', 3800, 150, 850, 212.5, 425], ['Kris', '14', 6210, 150, 2000, 500, 1000],
                ['Kris', '15', 5814, 215, 1500, 375, 750], ['Kris', '15', 2189, 10, 500, 125, 250], ['Kris', '16', 4325, 215, 1200, 300, 600],
                ['Kris', '16', 1620, 300, 500, 125, 250],
            ];
            foreach ($jobs as $i => [$team, $day, $amount, $parts, $companyParts, $share, $external]) {
                $row(['Date' => "2026-10-{$day}", 'Team' => $team, 'Record type' => 'Job', 'Technicians' => 'Sales '.($i % 3),
                    'Job Number' => 9000 + $i, 'Job Amount' => $amount, 'Parts Cost' => $parts, 'Company Parts' => $companyParts,
                    'Tech Share' => $share, 'Total Tech Share' => $share, 'Total External Share' => $external]);
            }
            // [team, hours, employee, helper, operation, gas]; a summary's own Tech Share is formula residue.
            foreach ([['Darren', 40, 1600, 750, 375, 257], ['John', 41.5, 1267.5, 750, 375, 257], ['Kris', 40, 1200, null, 375, 257], ['Ricardo', 40, 1120, null, 375, 257.01]] as [$team, $h, $e, $hl, $op, $gas]) {
                $row(array_filter(['Date' => '2026-10-18', 'Team' => $team, 'Record type' => 'Weekly Summary', 'Total Hours ' => $h, 'Employee Cost' => $e,
                    'Helper Cost' => $hl, 'Ave Operation Cost' => $op, 'Gas Cost' => $gas, 'Company Parts' => $e, 'Tech Share' => $e / 4,
                    'Total External Share' => $e / 2], fn ($v) => $v !== null));
            }
            // Next week's row stays out.
            $row(['Date' => '2026-10-19', 'Team' => 'Darren', 'Record type' => 'Job', 'Job Number' => 1, 'Job Amount' => 99999, 'Tech Share' => 1]);
            FinancialSource::create(['base_id' => 'appR', 'table_id' => 'tblNJ', 'role' => 'repair', 'franchise_id' => $t['franchise']->id,
                'company_id' => $t['company']->id, 'markers' => ['summary' => 'Weekly Summary', 'job' => 'Job']]);
        });

        $as = $this->signedInAs($t['admin']);
        $place = $as->getJson('/api/v1/financials/repair-report?week_start=2026-10-12')->assertOk()
            ->assertJsonPath('from', '2026-10-12')->assertJsonPath('to', '2026-10-18')->json('data.0');

        $this->assertSame('calculated', $place['status']);
        $this->assertNull($place['approval']);
        $this->assertSame(['external_share', 'contracting', 'approval'], $place['not_in_source']);
        $cards = collect($place['technicians'])->keyBy('technician');
        $this->assertSame(['Darren', 'John', 'Kris', 'Ricardo'], $cards->keys()->all());

        $darren = $cards['Darren'];
        $this->assertEquals(40, $darren['hours']);
        $this->assertSame([160000, 75000, 37500, 25700, 72000, 370200], [$darren['paycheck_cents'], $darren['helper_cents'],
            $darren['operation_cents'], $darren['gas_cents'], $darren['parts_cents'], $darren['total_cost_cents']]);
        $this->assertEquals(['cents' => 146250, 'pct' => 39.5], $darren['tech_share']);
        $this->assertEquals(['cents' => 0, 'pct' => 0], $darren['external_share']);
        $this->assertEquals(['cents' => 0, 'pct' => 0], $darren['contracting_profit']);
        $this->assertEquals(['cents' => 223950, 'pct' => 60.5], $darren['company_share']);
        $this->assertEquals([4, 1986000, 11.3], [$darren['jobs'], $darren['jobs_amount_cents'], $darren['cost_from_gross_pct']]);

        // Ricardo had no jobs: costs only, no gross to divide by.
        $ricardo = $cards['Ricardo'];
        $this->assertSame([0, 0, 175201, null], [$ricardo['jobs'], $ricardo['jobs_amount_cents'], $ricardo['total_cost_cents'], $ricardo['cost_from_gross_pct']]);

        $all = $place['combined'];
        $this->assertEquals([4, 161.5, 14, 6606100], [$all['count'], $all['hours'], $all['jobs'], $all['jobs_amount_cents']]);
        $this->assertSame(1407751, $all['total_cost_cents']);
        $this->assertEquals(['cents' => 511800, 'pct' => 36.4], $all['tech_share']);
        $this->assertEquals(['cents' => 895951, 'pct' => 63.6], $all['company_share']);
        $this->assertEquals(13.6, $all['cost_from_gross_pct']);
        // Recomputed from the cents, not trusted from the endpoint.
        $this->assertSame(36.4, round(511800 * 100 / 1407751, 1));
        $this->assertSame(63.6, round(895951 * 100 / 1407751, 1));
        $this->assertSame($all['total_cost_cents'], array_sum(array_column($place['technicians'], 'total_cost_cents')));

        // from/to works too, and a mapped external share and approval are read.
        $this->inTenant($t['org'], fn () => FinancialSource::query()->update(['field_map' => json_encode(['external_share' => 'Total External Share', 'approval' => 'Approval'])]));
        $mapped = $as->getJson('/api/v1/financials/repair-report?from=2026-10-12&to=2026-10-18')->assertOk()->json('data.0');
        $this->assertSame(292500, collect($mapped['technicians'])->firstWhere('technician', 'Darren')['external_share']['cents']);
        $this->assertSame(['contracting'], $mapped['not_in_source']);
        $this->assertTrue($mapped['approval_mapped']);

        $as->getJson('/api/v1/financials/repair-report')->assertStatus(422);
    }

    public function test_the_gate_is_the_repair_splits(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nobody = $this->userWithRole($t, 'No books', ['financials.view']);
        $this->signedInAs($nobody)->getJson('/api/v1/financials/repair-report?week_start=2026-10-12')->assertForbidden();
        $this->assertStringContainsString('financials/repair-report [week_start, from, to, franchise_id, company_id]', AppAsk::catalogue());
    }
}
