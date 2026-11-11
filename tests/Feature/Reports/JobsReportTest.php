<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\JobsReportController;
use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Jobs (Jose, 2026-11-11): every job created, by source, number dialed and area, with both ids. */
class JobsReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_every_created_job_with_both_ids_the_number_dialed_and_server_side_filters(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($f) {
            $ezbizi = Job::factory()->create(['franchise_id' => $f->id, 'customer_id' => Customer::factory()->create(['owning_franchise_id' => $f->id])->id]);
            $job = fn (string $ref, string $source, string $area, string $booked, float $total, array $more = []) => WorkizJob::query()->create([
                'franchise_id' => $f->id, 'remote_id' => $ref, 'serial_id' => '6000'.substr($ref, -2), 'job_source' => $source, 'service_area' => $area,
                'status' => 'Submitted', 'booked_at' => $booked, 'job_type' => 'Chimney Sweep', 'created_by' => 'Dov',
                'payload' => ['FirstName' => 'Ann', 'LastName' => 'Lee', 'Phone' => '(212) 555-0101', 'JobTotalPrice' => $total, 'v2' => ['id' => 'JOB-'.$ref]] + $more,
                'synced_at' => now(),
            ] + ($ref === 'J01' ? ['job_id' => $ezbizi->id] : []));
            $job('J01', 'LS Ads', 'Cleveland', '2026-11-10 09:00:00', 300);
            $job('J02', 'GMB', 'Cleveland', '2026-11-10 10:00:00', 200);
            $job('J03', 'LS Ads', 'DFW', '2026-11-09 10:00:00', 100);
            $job('J04', 'LS Ads', 'DFW', '2026-11-01 10:00:00', 999);
            DB::table('workiz_calls')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f->organization_id, 'franchise_id' => $f->id,
                'remote_id' => 'CAL-1', 'direction' => 'inbound', 'from_number' => '+12125550101', 'to_number' => '+12165550000',
                'job_remote_id' => 'JOB-J01', 'called_at' => '2026-11-10 08:55:00', 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $get = fn (string $query = '') => $this->signedInAs($t['admin'])->getJson('/api/v1/reports/jobs?from=2026-11-09&to=2026-11-10'.$query)->assertOk();

        $all = $get();
        $all->assertJsonPath('data.count', 3)->assertJsonPath('data.total', 600)->assertJsonPath('data.rows.0.workiz_id', 'J02');
        $first = collect($all->json('data.rows'))->firstWhere('workiz_id', 'J01');
        $this->assertSame(['6000'.'01', 'LS Ads', 'Cleveland', '+12165550000', 'Ann Lee', 'Dov'],
            [$first['workiz_number'], $first['source'], $first['service_area'], $first['dialed'], $first['client'], $first['created_by']]);
        $this->assertNotNull($first['ezbizi_number']);
        $this->assertNull(collect($all->json('data.rows'))->firstWhere('workiz_id', 'J02')['ezbizi_number']);
        $this->assertSame(['LS Ads' => 2, 'GMB' => 1], collect($all->json('data.sources'))->pluck('count', 'value')->all());

        $get('&source=LS%20Ads')->assertJsonPath('data.count', 2)->assertJsonPath('data.total', 400);
        $get('&area=DFW')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J03');
        $get('&dialed=%2B12165550000')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J01');
        $get('&dialed=none')->assertJsonPath('data.count', 2);
        // The search runs on the server too: a Workiz job number, or three digits of the phone.
        $get('&q=600003')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J03');
        $get('&q=555-0101')->assertJsonPath('data.count', 3);
        // The count and total are the range's; the rows a page of them.
        $get('&limit=1&offset=1')->assertJsonPath('data.count', 3)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.workiz_id', 'J01');

        $this->signedInAs($this->userWithRole($t, 'none', ['reports.view']))->getJson('/api/v1/reports/jobs?from=2026-11-09&to=2026-11-10')->assertForbidden();
    }

    /** With no Workiz call tied to it, the number dialed is the client's last call in before the job was booked. */
    public function test_the_number_dialed_is_the_clients_last_call_before_the_job(): void
    {
        $job = fn (string $id, string $phone) => (object) ['workiz_id' => $id, 'phone' => $phone, 'created_at' => '2026-11-10 09:00:00', 'timezone' => 'America/New_York'];
        // Newest first, as forPeriod gives them: 9 AM New York is 14:00 UTC.
        $log = [
            ['from' => '+12125550199', 'to_number' => '+14405550000', 'started_at' => '2026-11-10T15:00:00Z'],
            ['from' => '+12125550199', 'to_number' => '+13305550000', 'started_at' => '2026-11-10T13:50:00Z'],
            ['from' => '+12125550199', 'to_number' => '+16145550000', 'started_at' => '2026-11-09T13:50:00Z'],
            ['from' => '+13125550100', 'to_number' => '+16145550000', 'started_at' => '2026-10-20T13:50:00Z'],
        ];

        $this->assertSame(['J1' => '+13305550000'],
            JobsReportController::lastCallIn($log, collect([$job('J1', '(212) 555-0199'), $job('J2', '(312) 555-0100'), $job('J3', '')]), 'UTC'));
    }
}
