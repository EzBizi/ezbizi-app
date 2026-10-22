<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose: the weekly Repair Team report ("WhatsApp Shoval Report"), from the place's repair sheet. */
class RepairWeekTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_week_adds_up_per_team_is_approved_reopens_on_an_edit_and_feeds_production_cost(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $row = fn (string $id, array $f) => AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appA', 'table_id' => 'tblRep',
                'table_name' => 'NJ', 'record_id' => $id, 'fields' => $f, 'fields_hash' => md5(json_encode($f)), 'fetched_at' => now()]);
            // The A-1 sheet's own column names, week of Mon 2026-10-12.
            $row('j1', ['Date' => '2026-10-13', 'Team' => 'Darren', 'Record type' => 'Job', 'Job Number' => 1, 'Job Amount' => 2000, 'Parts Cost' => 100, 'Tech Share' => 125]);
            $row('j2', ['Date' => '2026-10-15', 'Team' => 'Darren', 'Record type' => 'Job', 'Job Number' => 2, 'Job Amount' => 1000, 'Tech Share' => 75]);
            $row('s1', ['Date' => '2026-10-18', 'Team' => 'Darren', 'Record type' => 'Weekly Summary', 'Total Hours ' => 40, 'Employee Cost' => 1200, 'Helper Cost' => 300, 'Ave Operation Cost' => 375, 'Gas Cost' => 25]);
            $row('j3', ['Date' => '2026-10-14', 'Team' => 'Kris', 'Record type' => 'Job', 'Job Number' => 3, 'Job Amount' => 500]);
            $row('old', ['Date' => '2026-10-11', 'Team' => 'Darren', 'Record type' => 'Job', 'Job Number' => 9, 'Job Amount' => 99999]);
            FinancialSource::create(['base_id' => 'appA', 'table_id' => 'tblRep', 'role' => 'repair', 'franchise_id' => $t['franchise']->id, 'company_id' => $t['company']->id]);
        });
        $as = $this->signedInAs($t['admin']);
        $q = "company_id={$t['franchise']->id}&week_start=2026-10-15";

        $r = $as->getJson("/api/v1/financials/repair-week?{$q}")->assertOk()->json('data');
        $this->assertSame(['2026-10-12', '2026-10-18', 'draft'], [$r['from'], $r['to'], $r['status']]);
        [$darren, $kris] = $r['techs'];
        $this->assertSame('Darren', $darren['name']);
        // 1200 + 300 + 375 + 25 + 100
        $this->assertSame(200000, $darren['total_cost_minor']);
        $this->assertSame([2, 300000, 66.7], [$darren['jobs_done'], $darren['jobs_amount_minor'], $darren['cost_from_gross_pct']]);
        $this->assertSame(['minor' => 20000, 'pct' => 6.7], $darren['tech_share']);
        // 3000 − 2000 − 200 (no external share column is mapped)
        $this->assertSame(80000, $darren['company_share']['minor']);
        $this->assertSame([], $darren['missing']);
        $this->assertSame(['hours', 'paycheck', 'avg_operation'], $kris['missing']);
        $this->assertSame([2, 3, 350000], [$r['combined']['count'], $r['combined']['jobs_done'], $r['combined']['jobs_amount_minor']]);
        $this->assertStringContainsString('Weekly 2026-10-12 to 2026-10-18* · UNAPPROVED DRAFT', $r['text']);
        $this->assertStringContainsString('Missing: hours, paycheck, avg_operation', $r['text']);

        $as->postJson('/api/v1/financials/repair-week/approve', ['company_id' => $t['franchise']->id, 'week_start' => '2026-10-12'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', 'Alpha Admin');

        // A typed-in value fills the gap and reopens the week.
        $after = $as->patchJson('/api/v1/financials/repair-week/value', ['company_id' => $t['franchise']->id, 'week_start' => '2026-10-12',
            'team' => 'Kris', 'field' => 'paycheck', 'value' => 400])->assertOk()->json('data');
        $this->assertSame('draft', $after['status']);
        $this->assertSame([40000, ['hours', 'avg_operation']], [$after['techs'][1]['paycheck_minor'], $after['techs'][1]['missing']]);

        $table = $as->getJson("/api/v1/financials/repair-week/table?company_id={$t['franchise']->id}&from=2026-10-05&to=2026-10-18")->assertOk()->json('data');
        $this->assertSame([['2026-10-05', 'Darren'], ['2026-10-12', 'Darren'], ['2026-10-12', 'Kris']], array_map(fn ($x) => [$x['week_start'], $x['name']], $table));

        $cost = collect($as->getJson('/api/v1/financials/production-cost?from=2026-10-12&to=2026-10-18')->assertOk()->json('data'))->keyBy('team');
        $this->assertSame(['2026-10', 2, 200000, 100000], [$cost['Darren']['month'], $cost['Darren']['jobs'], $cost['Darren']['cost_minor'], $cost['Darren']['avg_minor']]);

        // Seeing is not approving; another tenant does not find the place.
        $viewer = $this->userWithRole($t, 'Viewer', ['financials.view', 'financials.bases.view']);
        $this->signedInAs($viewer)->getJson("/api/v1/financials/repair-week?{$q}")->assertOk();
        $this->signedInAs($viewer)->postJson('/api/v1/financials/repair-week/approve', ['company_id' => $t['franchise']->id, 'week_start' => '2026-10-12'])->assertForbidden();
        $this->signedInAs($viewer)->patchJson('/api/v1/financials/repair-week/value', ['company_id' => $t['franchise']->id, 'week_start' => '2026-10-12',
            'team' => 'Kris', 'field' => 'paycheck', 'value' => 1])->assertForbidden();
        $beta = $this->provisionTenant('Beta');
        $this->signedInAs($beta['admin'])->getJson("/api/v1/financials/repair-week?{$q}")->assertNotFound();
    }
}
