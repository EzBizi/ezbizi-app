<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Recruiting (Shoval, 2026-11-18) on HR's applicants, interviews and spend (HR, 2026-11-20). */
class RecruitingReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_position_spend_inquiries_response_interviews_relevant_hires_and_cost_per_hire(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $org = $t['org']->id;
        $applicant = function (string $position, string $applied, array $more = []) use ($org, $f) {
            $id = (string) Str::uuid();
            DB::table('applicants')->insert($more + ['id' => $id, 'organization_id' => $org, 'franchise_id' => $f->id, 'name' => 'A '.$id,
                'position' => $position, 'stage' => 'applied', 'applied_at' => $applied, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $interview = fn (string $applicant, string $kind, ?bool $held) => DB::table('applicant_interviews')->insert(['id' => (string) Str::uuid(),
            'organization_id' => $org, 'applicant_id' => $applicant, 'kind' => $kind, 'scheduled_at' => '2026-11-12 15:00:00', 'held' => $held,
            'created_at' => now(), 'updated_at' => now()]);

        // Dispatcher: three applied in the week; answered in 2 and 4 hours; one hired after two interviews.
        $hired = $applicant('Dispatcher', '2026-11-10 14:00:00', ['first_response_at' => '2026-11-10 16:00:00', 'relevant' => true, 'stage' => 'hired']);
        $interview($hired, 'phone', true);
        $interview($hired, 'in_person', true);
        $other = $applicant('Dispatcher', '2026-11-11 14:00:00', ['first_response_at' => '2026-11-11 18:00:00', 'relevant' => false]);
        $interview($other, 'in_person', null); // not held: not counted
        $applicant('Dispatcher', '2026-11-12 14:00:00');
        // Technician: one applied, none hired.
        $applicant('Technician', '2026-11-12 14:00:00', ['relevant' => true]);
        // Outside the dates.
        $applicant('Dispatcher', '2026-10-01 14:00:00', ['stage' => 'hired']);
        // November's spend; October's is not in the dates.
        foreach ([['2026-11-01', 'Dispatcher', 300], ['2026-11-01', 'Technician', 150], ['2026-10-01', 'Dispatcher', 999]] as [$month, $position, $amount]) {
            DB::table('recruiting_spend')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'franchise_id' => $f->id,
                'month' => $month, 'position' => $position, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/recruiting?from=2026-11-09&to=2026-11-15')->assertOk()->json('data');

        $by = collect($data['rows'])->keyBy('position');
        $this->assertSame(['position' => 'Dispatcher', 'spend_minor' => 30000, 'inquiries' => 3, 'avg_hours_to_respond' => 3, 'not_responded' => 1,
            'interviews' => 2, 'in_person' => 1, 'relevant' => 1, 'hires' => 1, 'cost_per_hire_minor' => 30000], $by['Dispatcher']);
        $this->assertSame([15000, 1, 1, 0, null], [$by['Technician']['spend_minor'], $by['Technician']['inquiries'], $by['Technician']['relevant'],
            $by['Technician']['hires'], $by['Technician']['cost_per_hire_minor']]);
        $this->assertSame([45000, 4, 1, 45000], [$data['totals']['spend_minor'], $data['totals']['inquiries'], $data['totals']['hires'], $data['totals']['cost_per_hire_minor']]);
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Office', ['reports.view']))
            ->getJson('/api/v1/reports/recruiting?from=2026-11-09&to=2026-11-15')->assertForbidden();
    }
}
