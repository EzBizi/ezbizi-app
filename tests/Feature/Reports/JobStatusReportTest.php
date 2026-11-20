<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SchedulingTechnician;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Cancelled jobs and Pending jobs (Shoval, 2026-11-18), on EzBizi's own jobs and the business's statuses. */
class JobStatusReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_cancelled_jobs_created_in_the_dates_against_every_job_created(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($f) {
            $client = Customer::factory()->create(['owning_franchise_id' => $f->id, 'name' => 'Ann Lee', 'phone' => '+12125550101']);
            $job = function (string $status, ?string $reason, string $createdUtc, array $more = []) use ($f, $client) {
                $j = Job::factory()->create($more + ['franchise_id' => $f->id, 'customer_id' => $client->id, 'status' => $status,
                    'sub_status' => $reason, 'job_source' => 'LS Ads', 'amount_minor' => 15000]);
                $j->forceFill(['created_at' => $createdUtc])->save();

                return $j;
            };
            $made = $job('Canceled', 'Client Requested Cancellation', '2026-11-10 16:00:00', ['number' => 'A-1']);
            // Imported from Workiz today, booked on the 9th: Created is the booking, not the import.
            $imported = $job('Canceled', 'Estimate Declined', now()->toDateTimeString(), ['number' => 'B-1', 'job_source' => 'PPC']);
            WorkizJob::query()->create(['franchise_id' => $f->id, 'job_id' => $imported->id, 'remote_id' => 'W1', 'serial_id' => '6001',
                'status' => 'Canceled', 'booked_at' => '2026-11-09 10:00:00', 'last_status_update' => '2026-11-09 18:00:00', 'payload' => [], 'synced_at' => now()]);
            $job('Done', null, '2026-11-10 17:00:00');
            $job('Canceled', 'Duplicate Job', '2026-11-01 16:00:00');
            $ron = SchedulingTechnician::create(['franchise_id' => $f->id, 'name' => 'Ron']);
            DB::table('job_technicians')->insert(['job_id' => $made->id, 'technician_id' => $ron->id, 'source' => 'ezbizi', 'created_at' => now(), 'updated_at' => now()]);
        });
        $get = fn (string $query = '') => $this->signedInAs($t['admin'])->getJson('/api/v1/reports/cancelled?from=2026-11-09&to=2026-11-10'.$query)->assertOk();

        $all = $get();
        // Two of the three created in the dates; the one created on the 1st is not in them.
        $all->assertJsonPath('data.count', 2)->assertJsonPath('data.created', 3)->assertJsonPath('data.value_minor', 30000);
        $this->assertSame(['A-1', 'B-1'], collect($all->json('data.rows'))->pluck('number')->all());
        $b = $all->json('data.rows.1');
        $this->assertSame(['2026-11-09 10:00:00', '2026-11-09 18:00:00', '6001', 'Estimate Declined', 'PPC'],
            [$b['created_at'], $b['since'], $b['workiz_number'], $b['reason'], $b['source']]);
        $this->assertSame([['Ron'], 'Ann Lee', '+12125550101'], [$all->json('data.rows.0.techs'), $all->json('data.rows.0.client'), $all->json('data.rows.0.phone')]);
        $this->assertSame(['Client Requested Cancellation' => 1, 'Estimate Declined' => 1], collect($all->json('data.reasons'))->pluck('count', 'value')->all());
        $this->assertSame(['Ron' => 1, 'none' => 1], collect($all->json('data.techs'))->pluck('count', 'value')->all());

        $get('&reason[]=Estimate%20Declined')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.number', 'B-1')->assertJsonPath('data.all', 2);
        $get('&tech[]=Ron')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.number', 'A-1');
        $get('&tech[]=none')->assertJsonPath('data.rows.0.number', 'B-1');
        $get('&q=declined')->assertJsonPath('data.count', 1);
        $get('&source[]=PPC')->assertJsonPath('data.count', 1);
    }

    public function test_pending_jobs_now_oldest_first_by_the_business_statuses(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($f) {
            $client = Customer::factory()->create(['owning_franchise_id' => $f->id]);
            foreach ([['P-new', 'pending', '2026-11-15 16:00:00'], ['P-old', 'Pending', '2026-10-01 16:00:00'], ['H-1', 'On hold', '2026-11-01 16:00:00'],
                ['D-1', 'Done', '2026-11-01 16:00:00']] as [$number, $status, $at]) {
                Job::factory()->create(['franchise_id' => $f->id, 'customer_id' => $client->id, 'number' => $number, 'status' => $status])->forceFill(['created_at' => $at])->save();
            }
        });
        $as = $this->signedInAs($t['admin']);

        $pending = $as->getJson('/api/v1/reports/pending')->assertOk();
        $this->assertSame(['P-old', 'P-new'], collect($pending->json('data.rows'))->pluck('number')->all());
        $this->assertGreaterThan(30, $pending->json('data.rows.0.age_days'));

        // The business says "On hold" is pending too.
        $as->postJson('/api/v1/settings/lists/reports.pending_statuses/items', ['value' => ['status' => 'On hold']])->assertSuccessful();
        $this->assertSame(['P-old', 'H-1', 'P-new'], collect($as->getJson('/api/v1/reports/pending')->assertOk()->json('data.rows'))->pluck('number')->all());
    }

    public function test_each_needs_its_own_tab(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($this->userWithRole($t, 'marketer', ['reports.view', 'reports.pending.view']));

        $as->getJson('/api/v1/reports/pending')->assertOk();
        $as->getJson('/api/v1/reports/cancelled?from=2026-11-09&to=2026-11-10')->assertForbidden();
    }
}
