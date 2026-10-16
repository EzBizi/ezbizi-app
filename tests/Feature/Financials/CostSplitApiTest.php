<?php

namespace Tests\Feature\Financials;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CostSplitApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_split_view_shares_summary_costs_by_job_count_and_refuses_a_repeated_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($tenant['admin']);
        $base = $as->postJson('/api/v1/financials/bases', ['name' => 'Repairs'])->json('data');
        $t = $base['tables'][0]['id'];
        $f = fn (string $name, string $type, array $options = []) => $as->postJson("/api/v1/financials/tables/{$t}/fields", ['name' => $name, 'type' => $type, 'options' => $options])->json('data.id');
        $team = $f('Team', 'text');
        $tech = $f('Tech', 'text');
        $kind = $f('Kind', 'single_select', ['choices' => [['name' => 'Job'], ['name' => 'Summary']]]);
        $job = $f('Job #', 'text');
        $pay = $f('Paycheck', 'currency');
        $gas = $f('Gas', 'currency');
        $date = $f('Date', 'date');

        $add = fn (array $v) => $as->postJson("/api/v1/financials/tables/{$t}/records", ['values' => $v])->assertCreated();
        foreach ([['A', 'Ann', '1'], ['A', 'Bob', '2'], ['A', 'Bob', '3'], ['B', 'Cy', '4'], ['B', 'Cy', '4']] as [$g, $p, $n]) {
            $add([$team => $g, $tech => $p, $kind => 'Job', $job => $n, $date => '2026-10-05']);
        }
        $add([$team => 'A', $kind => 'Summary', $pay => '700.00', $gas => '0.01', $date => '2026-10-05']);
        $add([$team => 'A', $kind => 'Summary', $pay => '999', $date => '2026-09-01']); // outside the week

        $view = $as->postJson("/api/v1/financials/tables/{$t}/views", ['name' => 'Split', 'type' => 'split', 'config' => [
            'group_field' => $team, 'person_field' => $tech, 'kind_field' => $kind, 'job_kind' => 'Job', 'cost_kind' => 'Summary',
            'job_key_field' => $job, 'cost_fields' => [$pay, $gas], 'date_field' => $date,
        ]])->json('data.id');

        $split = $as->getJson("/api/v1/financials/views/{$view}/split?from=2026-10-05&to=2026-10-11")->assertOk()->json('data');

        $this->assertSame('calculated', $split[0]['status']);
        $this->assertSame(70001, $split[0]['total_cents']);
        $this->assertSame([['person' => 'Ann', 'jobs' => 1, 'cents' => 23334], ['person' => 'Bob', 'jobs' => 2, 'cents' => 46667]], $split[0]['people']);
        $this->assertSame(['unavailable', 'Job 4 appears twice.'], [$split[1]['status'], $split[1]['reason']]);
    }
}
