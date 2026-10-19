<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use App\Models\Franchise;
use App\Support\Ai\AppAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SourceFiguresTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_repair_split_and_refunds_come_from_the_mapped_sources_and_stay_inside_the_view(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$nj, $ct] = [$t['franchise'], $this->inTenant($t['org'], fn () => Franchise::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Alpha F2',
        ]))];

        $this->inTenant($t['org'], function () use ($t, $nj, $ct) {
            $row = function (string $table, string $id, array $fields) use ($t) {
                AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appA', 'table_id' => $table, 'table_name' => $table,
                    'record_id' => $id, 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now()]);
            };
            // NJ repair sheet: two jobs for Ann, one for Bob, one summary of $300.01.
            foreach ([['r1', 'Ann', 1], ['r2', 'Ann', 2], ['r3', 'Bob', 3]] as [$id, $tech, $n]) {
                $row('tblRep', $id, ['Date' => '2026-10-06', 'Team' => 'Team A', 'Record type' => 'Job', 'Tech Name' => $tech, 'Job Number' => $n]);
            }
            $row('tblRep', 'r4', ['Date' => '2026-10-11', 'Team' => 'Team A', 'Record type' => 'Weekly Summary', 'Employee Cost' => 300, 'Gas Cost' => 0.01]);
            $row('tblRep', 'r5', ['Date' => '2026-09-01', 'Team' => 'Team A', 'Record type' => 'Weekly Summary', 'Employee Cost' => 999]);
            FinancialSource::create(['base_id' => 'appA', 'table_id' => 'tblRep', 'role' => 'repair', 'franchise_id' => $nj->id, 'company_id' => $t['company']->id,
                'markers' => ['summary' => 'Weekly Summary', 'job' => 'Job']]);

            // One shared refunds table, split by Company Name.
            $row('tblRef', 'f1', ['Date' => '2026-10-07', 'Company Name' => 'NJ', 'Technician Name' => 'Ann', 'Refund/dispute Amount' => 50.5, 'Type' => 'Refund']);
            $row('tblRef', 'f2', ['Date' => '2026-10-08', 'Company Name' => 'NJ', 'Technician Name' => 'Ann', 'Refund/dispute Amount' => 10, 'Type' => 'Refund']);
            $row('tblRef', 'f3', ['Date' => '2026-10-08', 'Company Name' => 'CT', 'Technician Name' => 'Cy', 'Refund/dispute Amount' => 70, 'Type' => 'Refund']);
            foreach (['NJ' => $nj, 'CT' => $ct] as $code => $place) {
                FinancialSource::create(['base_id' => 'appA', 'table_id' => 'tblRef', 'role' => 'refunds', 'franchise_id' => $place->id,
                    'company_id' => $t['company']->id, 'filter_field' => 'Company Name', 'filter_value' => $code]);
            }
        });

        $week = 'from=2026-10-05&to=2026-10-11';
        $split = $this->signedInAs($t['admin'])->getJson("/api/v1/financials/repair-split?{$week}")->assertOk()->json('data');
        $this->assertSame(30001, $split[0]['groups'][0]['total_cents']);
        $this->assertSame([['person' => 'Ann', 'jobs' => 2, 'cents' => 20001], ['person' => 'Bob', 'jobs' => 1, 'cents' => 10000]], $split[0]['groups'][0]['people']);

        $refunds = collect($this->signedInAs($t['admin'])->getJson("/api/v1/financials/refunds?{$week}")->assertOk()->json('data'))->keyBy('place');
        $this->assertSame(6050, $refunds['Alpha F1']['total_cents']);
        $this->assertSame([['technician' => 'Ann', 'count' => 2, 'cents' => 6050]], $refunds['Alpha F1']['technicians']);
        $this->assertSame(7000, $refunds['Alpha F2']['total_cents']);

        // Somebody who works only at NJ never sees CT's refunds.
        $njOnly = $this->userWithRole($t, 'NJ staff', ['financials.view', 'financials.bases.view'], onlyAt: $nj);
        $places = collect($this->signedInAs($njOnly)->getJson("/api/v1/financials/refunds?{$week}")->assertOk()->json('data'))->pluck('place')->all();
        $this->assertSame(['Alpha F1'], $places);

        $this->signedInAs($t['admin'])->getJson('/api/v1/financials/refunds?from=2026-01-01&to=2026-06-01')->assertStatus(422);
    }

    public function test_the_staff_bot_can_find_both_reads(): void
    {
        $catalogue = AppAsk::catalogue();
        $this->assertStringContainsString('financials/repair-split [from, to, franchise_id]', $catalogue);
        $this->assertStringContainsString('financials/refunds [from, to, franchise_id]', $catalogue);
    }
}
