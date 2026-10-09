<?php

namespace Tests\Feature\Leads;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A lead is linked to the job it became by the customer's phone number: a
 * Workiz client with that number and a job booked from a day before the lead
 * to 30 days after it. The earliest such job wins; a lead that already has a
 * job - booked from the lead itself - is never touched.
 */
class MatchLeadsToJobsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28T12:00:00Z');
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function job(string $number): Job
    {
        return $this->inTenant($this->t['org'], function () use ($number) {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-'.$number, 'name' => 'A Customer']);

            return Job::create([
                'franchise_id' => $this->t['franchise']->id,
                'customer_id' => $customer->id,
                'number' => $number,
                'title' => 'Sweep',
                'status' => 'Done',
                'currency' => 'USD',
            ]);
        });
    }

    private function workiz(string $clientId, string $phone, array $bookings): void
    {
        $this->inTenant($this->t['org'], function () use ($clientId, $phone, $bookings) {
            WorkizClient::create([
                'franchise_id' => $this->t['franchise']->id,
                'client_id' => $clientId,
                'name' => 'A Customer',
                'phone' => $phone,
                'phone_digits' => preg_replace('/\D/', '', $phone),
                'synced_at' => now(),
            ]);
            foreach ($bookings as [$bookedAt, $job]) {
                WorkizJob::create([
                    'franchise_id' => $this->t['franchise']->id,
                    'remote_id' => strtoupper(bin2hex(random_bytes(4))),
                    'client_id' => $clientId,
                    'job_id' => $job?->id,
                    'booked_at' => Carbon::parse($bookedAt),
                    'status' => 'Done',
                    'payload' => [],
                    'synced_at' => now(),
                ]);
            }
        });
    }

    private function lead(string $id, string $phone, string $at, ?Job $job = null): Lead
    {
        return $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id,
            'source' => 'local_services',
            'remote_id' => $id,
            'phone' => $phone,
            'phone_digits' => preg_replace('/\D/', '', $phone),
            'received_at' => Carbon::parse($at),
            'job_id' => $job?->id,
        ]));
    }

    public function test_a_lead_gets_the_earliest_job_booked_in_its_window(): void
    {
        $early = $this->job('J-1');
        $later = $this->job('J-2');
        $before = $this->job('J-0');
        // Workiz keeps the country code; Google does not.
        $this->workiz('101', '+1 (215) 555-0101', [
            ['2026-08-01T10:00:00Z', $before],   // weeks before the lead: another visit
            ['2026-09-20T10:00:00Z', $later],
            ['2026-09-12T10:00:00Z', $early],
        ]);
        $lead = $this->lead('a', '215-555-0101', '2026-09-10T15:00:00Z');

        $this->artisan('ezbizi:match-leads-to-jobs')->assertSuccessful();

        $lead = Lead::withoutGlobalScopes()->find($lead->id);
        $this->assertSame($early->id, $lead->job_id);
        $this->assertSame('phone', $lead->job_matched_by);
        $this->assertSame(Lead::STATUS_COMPLETED, $lead->status, 'it became a job (Jose, 2026-10-09)');
    }

    public function test_nothing_outside_the_window_and_a_booked_lead_is_left_alone(): void
    {
        $booked = $this->job('J-B');
        $other = $this->job('J-X');
        $this->workiz('102', '2155550202', [['2026-09-11T10:00:00Z', $other]]);
        $this->workiz('103', '2155550303', [['2026-09-25T10:00:00Z', $other]]);

        $mine = $this->lead('b', '2155550202', '2026-09-10T15:00:00Z', $booked);
        $late = $this->lead('c', '2155550303', '2026-08-01T15:00:00Z');   // job 55 days later

        $this->artisan('ezbizi:match-leads-to-jobs', ['--all' => true])->assertSuccessful();

        $mine = Lead::withoutGlobalScopes()->find($mine->id);
        $this->assertSame($booked->id, $mine->job_id, 'a job booked from the lead is never replaced');
        $this->assertNull($mine->job_matched_by);
        $this->assertNull(Lead::withoutGlobalScopes()->find($late->id)->job_id);
    }
}
