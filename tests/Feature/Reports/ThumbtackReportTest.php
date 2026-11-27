<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13: "add the Thumbtack financials as well". */
class ThumbtackReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_spend_counts_charged_leads_and_booked_ones_cost(): void
    {
        $t = $this->provisionTenant('Alpha');
        $job = $this->makeJobId($t);

        foreach ([
            ['CHARGED', 1490, $job, '2026-10-05 10:00:00'],
            ['CHARGED', 2510, null, '2026-10-06 10:00:00'],
            ['CREDITED', 3000, null, '2026-10-06 11:00:00'],   // refunded: not spend
            ['CHARGED', 9999, null, '2026-09-01 10:00:00'],    // outside the dates
        ] as [$charge, $cost, $jobId, $at]) {
            DB::table('leads')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'franchise_id' => $t['franchise']->id, 'source' => 'thumbtack', 'is_test' => false,
                'remote_id' => Str::random(8), 'category' => 'Chimney Sweep', 'service_state' => 'CT', 'charge_status' => $charge,
                'lead_cost_minor' => $cost, 'job_id' => $jobId, 'received_at' => $at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $user = $this->userWithRole($t, 'Marketer', ['reports.thumbtack.view']);

        $this->signedInAs($user)
            ->getJson('/api/v1/reports/thumbtack?from=2026-10-01&to=2026-10-07')
            ->assertOk()
            ->assertJsonPath('data.rows.0', [
                'category' => 'Chimney Sweep', 'state' => 'CT', 'branch' => $t['franchise']->name, 'leads' => 3, 'charged' => 2,
                'credited' => 1, 'spend_minor' => 4000, 'cost_per_lead_minor' => 2000, 'booked' => 1,
                'cost_per_booked_minor' => 4000,
            ])
            ->assertJsonPath('data.categories', ['Chimney Sweep'])
            ->assertJsonPath('data.states', ['CT']);

        $this->signedInAs($user)
            ->getJson('/api/v1/reports/thumbtack?from=2026-10-01&to=2026-10-07&state=NJ')
            ->assertOk()
            ->assertJsonPath('data.rows', []);

        // A lead no branch has claimed still shows in a company's view, as "No
        // branch" (2026-11-04: all 19 of A-1's had none, so the view was empty).
        DB::table('leads')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'franchise_id' => null, 'source' => 'thumbtack', 'is_test' => false, 'remote_id' => Str::random(8),
            'category' => 'Chimney Sweep', 'service_state' => 'NY', 'charge_status' => 'CHARGED', 'lead_cost_minor' => 500,
            'received_at' => '2026-10-06 12:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->signedInAs($user)->withHeader('X-EzBizi-View', 'company:'.$t['company']->id)
            ->getJson('/api/v1/reports/thumbtack?from=2026-10-01&to=2026-10-07&state=NY')
            ->assertOk()
            ->assertJsonPath('data.rows.0.branch', null)
            ->assertJsonPath('data.rows.0.leads', 1);

        $this->signedInAs($this->userWithRole($t, 'Tech', []))
            ->getJson('/api/v1/reports/thumbtack?from=2026-10-01&to=2026-10-07')
            ->assertForbidden();
    }

    private function makeJobId(array $t): string
    {
        return $this->inTenant($t['org'], fn () => Job::factory()->create([
            'franchise_id' => $t['franchise']->id,
            'customer_id' => Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id])->id,
        ])->id);
    }
}
