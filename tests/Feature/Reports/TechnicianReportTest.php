<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\TechnicianReportController;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\SchedulingTechnician;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › AI checks and Tech report (Shoval, 2026-11-18): per technician, the four checks, revenue, complaints, redos. */
class TechnicianReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_technician_checks_and_report(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $client = Customer::factory()->create(['owning_franchise_id' => $f->id, 'phone' => null]);
            $ron = SchedulingTechnician::create(['franchise_id' => $f->id, 'name' => 'Ron']);
            $sam = SchedulingTechnician::create(['franchise_id' => $f->id, 'name' => 'Sam']);
            $job = function (string $number, string $status, string $type, ?string $start, array $techs, array $workiz = [], array $more = []) use ($f, $client) {
                $j = Job::factory()->create($more + ['franchise_id' => $f->id, 'customer_id' => $client->id, 'number' => $number, 'status' => $status,
                    'job_type' => $type, 'scheduled_for' => $start, 'amount_minor' => 9000]);
                foreach ($techs as $tech) {
                    DB::table('job_technicians')->insert(['job_id' => $j->id, 'technician_id' => $tech->id, 'source' => 'workiz', 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($workiz !== []) {
                    WorkizJob::query()->create(['franchise_id' => $f->id, 'job_id' => $j->id, 'remote_id' => 'W'.$number, 'serial_id' => '90'.$number,
                        'status' => $status, 'booked_at' => '2026-11-01 09:00:00', 'scheduled_for' => $workiz['start'], 'closed_at' => $workiz['closed'],
                        'payload' => ['Phone' => $workiz['phone'], 'Attachments' => array_fill(0, $workiz['photos'], ['Name' => 'Mobile upload']),
                            'JobTotalPrice' => $workiz['total']], 'synced_at' => now()]);
                }

                return $j;
            };
            // Called 1.5 hours ahead, closed 3 hours after the start, 4 photos.
            $job('1', 'Done', 'Chimney Inspection', null, [$ron], ['start' => '2026-11-10 10:00:00', 'closed' => '2026-11-10 13:00:00',
                'phone' => '(212) 555-0101', 'photos' => 4, 'total' => 300]);
            DB::table('workiz_calls')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'franchise_id' => $f->id,
                'remote_id' => 'CAL-1', 'direction' => 'outgoing', 'from_number' => '+13055550199', 'to_number' => '2125550101',
                'called_at' => '2026-11-10 08:30:00', 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            // No call, closed 19 hours after the start, no photos; both of them on it; a complaint about it.
            $late = $job('2', 'Done', 'Chimney Inspection', null, [$ron, $sam], ['start' => '2026-11-10 14:00:00', 'closed' => '2026-11-11 09:00:00',
                'phone' => '2125550102', 'photos' => 0, 'total' => 200]);
            $lead = Lead::query()->create(['organization_id' => $t['org']->id, 'company_id' => $f->company_id, 'franchise_id' => $f->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'L1', 'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW, 'received_at' => now()]);
            $lead->forceFill(['job_id' => $late->id])->save();
            Complaint::create(['organization_id' => $t['org']->id, 'company_id' => $f->company_id, 'franchise_id' => $f->id, 'kind' => 'call_complaint',
                'source_type' => 'lead', 'source_id' => $lead->id, 'status' => 'new', 'raised_at' => now()]);
            // EzBizi's own job, no phone, three photos of its own; a redo.
            $redo = $job('3', 'Done', 'Redo', '2026-11-10 20:00:00', [$sam]);
            foreach (range(1, 3) as $i) {
                DB::table('attachments')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'attachable_type' => 'jobs',
                    'attachable_id' => $redo->id, 'disk' => 'local', 'path' => "p{$i}.jpg", 'original_name' => "p{$i}.jpg", 'mime' => 'image/jpeg',
                    'size_bytes' => 10, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            }
            // Not counted: before the dates, and not done.
            $job('4', 'Done', 'Chimney Sweep', '2026-11-01 15:00:00', [$ron]);
            $job('5', 'Canceled', 'Chimney Sweep', '2026-11-10 15:00:00', [$ron]);
        });
        $as = $this->signedInAs($t['admin']);
        $dates = '?from=2026-11-10&to=2026-11-10';

        $checks = collect($as->getJson('/api/v1/reports/tech-checks'.$dates)->assertOk()->json('data.rows'))->keyBy('tech');
        $this->assertSame(['tech' => 'Ron', 'jobs' => 2, 'eta_missed' => 1, 'close_missed' => 1, 'inspection_missed' => null, 'photos_missed' => 1], $checks['Ron']);
        $this->assertSame(['tech' => 'Sam', 'jobs' => 2, 'eta_missed' => 1, 'close_missed' => 1, 'inspection_missed' => null, 'photos_missed' => 1], $checks['Sam']);

        $report = collect($as->getJson('/api/v1/reports/technicians'.$dates)->assertOk()->json('data.rows'))->keyBy('tech');
        $this->assertSame([50000, 1, 0], [$report['Ron']['revenue_minor'], $report['Ron']['complaints'], $report['Ron']['redo']]);
        $this->assertSame([29000, 1, 1], [$report['Sam']['revenue_minor'], $report['Sam']['complaints'], $report['Sam']['redo']]);

        // The jobs behind one cell.
        $this->assertSame(['2'], collect($as->getJson('/api/v1/reports/tech-checks'.$dates.'&tech=Ron&check=eta')->assertOk()->json('data.rows'))->pluck('number')->all());

        // The business says inspections need a report: neither of Ron's has one.
        $as->postJson('/api/v1/settings/lists/'.TechnicianReportController::INSPECTION_TYPES.'/items', ['value' => ['type' => 'Chimney Inspection']])->assertSuccessful();
        $this->assertSame(2, collect($as->getJson('/api/v1/reports/tech-checks'.$dates)->json('data.rows'))->keyBy('tech')['Ron']['inspection_missed']);
    }

    public function test_each_needs_its_own_tab(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($this->userWithRole($t, 'Office', ['reports.view']));

        $as->getJson('/api/v1/reports/technicians?from=2026-11-10&to=2026-11-10')->assertForbidden();
        $as->getJson('/api/v1/reports/tech-checks?from=2026-11-10&to=2026-11-10')->assertForbidden();
    }
}
