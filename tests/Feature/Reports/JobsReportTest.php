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
            $job('J03', 'LS Ads', 'DFW', '2026-11-09 10:00:00', 100)->forceFill(['created_at' => now()->subHours(2)])->save();
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
        // Called at: the tied call's start, on Workiz's wall clock like Created (Jose 11-12).
        $this->assertSame('2026-11-10 08:55:00', $first['called_at']);
        $this->assertNull(collect($all->json('data.rows'))->firstWhere('workiz_id', 'J02')['called_at']);
        $this->assertNull(collect($all->json('data.rows'))->firstWhere('workiz_id', 'J02')['ezbizi_number']);
        // No EzBizi job yet: syncing while just in from Workiz; an old one is not, so a stuck copy shows as a dash.
        $this->assertSame([false, true, false], collect(['J01', 'J02', 'J03'])
            ->map(fn (string $id) => collect($all->json('data.rows'))->firstWhere('workiz_id', $id)['ezbizi_syncing'])->all());
        $this->assertSame(['LS Ads' => 2, 'GMB' => 1], collect($all->json('data.sources'))->pluck('count', 'value')->all());

        $get('&source=LS%20Ads')->assertJsonPath('data.count', 2)->assertJsonPath('data.total', 400);
        $get('&area=DFW')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J03');
        $get('&dialed=%2B12165550000')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J01');
        $get('&dialed=none')->assertJsonPath('data.count', 2);
        // Several at once (Jose 11-12: "Multiselect for both numbers dialed and sources"), 'none' among them.
        $get('&source[]=GMB&source[]=none')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J02');
        $get('&source[]=GMB&source[]=LS%20Ads')->assertJsonPath('data.count', 3)->assertJsonPath('data.total', 600);
        $get('&dialed[]=none&dialed[]=%2B12165550000&area=DFW')->assertJsonPath('data.count', 1);
        // The search reads every column, any case (Jose 11-12: "so it gets all columns").
        $get('&q=600003')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.workiz_id', 'J03');
        $get('&q=555-0101')->assertJsonPath('data.count', 3);
        $search = fn (string $q) => collect($get('&q='.urlencode($q))->json('data.rows'))->pluck('workiz_id')->sort()->values()->all();
        $this->assertSame(['J01', 'J02'], $search('CLEVELAND'));
        $this->assertSame(['J02'], $search('gmb'));
        $this->assertSame(['J01'], $search('(216) 555-0000'));
        $this->assertSame(['J03'], $search('11/9/2026'));
        $this->assertSame(['J01'], $search('300.00'));
        $this->assertSame(['J01', 'J02', 'J03'], $search('chimney sweep'));
        $this->assertSame([], $search('nothing like it'));
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

        // When: 13:50 UTC is 8:50 AM in New York, the job's own clock.
        $this->assertSame(['J1' => ['+13305550000', '2026-11-10 08:50:00']],
            JobsReportController::lastCallIn($log, collect([$job('J1', '(212) 555-0199'), $job('J2', '(312) 555-0100'), $job('J3', '')]), 'UTC'));

        // The job's second phone counts, and its Workiz clock wins over the branch's: 9 AM Central is 15:00 UTC.
        $second = $job('J4', '(999) 555-0000');
        $second->phone2 = '212-555-0199';
        $second->clock = 'America/Chicago';
        $this->assertSame(['J4' => ['+14405550000', '2026-11-10 09:00:00']], JobsReportController::lastCallIn($log, collect([$second]), 'UTC'));
    }
}
