<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\WorkizRecord;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `job.estimate_aging`, once a day for each job in a status the organization
 * names (Jose's rule 2: "30 days without money → Canceled /
 * Estimate Declined"). The rule holds the threshold; this says how old the
 * LATEST estimate is and what has been paid.
 */
class JobEstimateAgingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_waiting_job_is_announced_with_its_latest_estimate_and_what_was_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        $alpha = $this->provisionTenant('Alpha');
        $this->statuses($alpha);

        $waiting = $this->aJob($alpha, 'done pending approval');
        $this->aJob($alpha, 'done pending approval');           // no estimate: skipped
        $done = $this->aJob($alpha, 'completed');
        $this->estimateOn($alpha, $waiting, '2026-09-16');       // ours, the latest: 35 days
        $this->workiz($alpha, $waiting, 'estimate', '2026-09-11', '900.00');
        $this->workiz($alpha, $waiting, 'payment', '2026-09-20', '100.00', ['type' => 'charge'], 'succeeded');
        $this->workiz($alpha, $waiting, 'payment', '2026-09-21', '30.00', ['type' => 'refund'], 'succeeded');
        $this->estimateOn($alpha, $done, '2026-09-01');

        $this->artisan('jobs:emit-estimate-aging')->assertSuccessful();
        $this->artisan('jobs:emit-estimate-aging')->assertSuccessful();   // same day: nothing new

        $events = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()->where('event_key', 'job.estimate_aging')->get());
        $this->assertCount(1, $events);
        $this->assertSame($waiting, $events[0]->subject_id);
        $this->assertEquals(['after' => [
            'status' => 'done pending approval', 'days_since_estimate' => 35,
            'estimate_date' => '2026-09-16', 'paid_total' => 7000,
        ]], $events[0]->payload);
    }

    private function statuses(array $alpha): void
    {
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $settings = app(SettingsResolver::class);
            $settings->set('jobs.statuses', $alpha['org'], [
                ['value' => 'scheduled', 'label' => 'Scheduled'],
                ['value' => 'done pending approval', 'label' => 'Done, pending approval'],
                ['value' => 'completed', 'label' => 'Completed'],
            ]);
            $settings->set('jobs.estimate_aging_statuses', $alpha['org'], ['done pending approval']);
        });
    }

    private function aJob(array $alpha, string $status): string
    {
        return $this->inTenant($alpha['org'], function () use ($alpha, $status) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);

            return Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => $status, 'currency' => 'USD'])->id;
        });
    }

    private function estimateOn(array $alpha, string $jobId, string $day): void
    {
        $this->inTenant($alpha['org'], function () use ($alpha, $jobId, $day) {
            $job = Job::query()->findOrFail($jobId);
            Estimate::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $job->customer_id, 'job_id' => $jobId,
                'number' => 'E-'.bin2hex(random_bytes(3)), 'status' => 'sent', 'issued_on' => $day, 'currency' => 'USD', 'total_minor' => 1000]);
        });
    }

    private function workiz(array $alpha, string $jobId, string $type, string $day, string $amount, array $payload = [], ?string $status = null): void
    {
        $this->inTenant($alpha['org'], fn () => WorkizRecord::create([
            'franchise_id' => $alpha['franchise']->id, 'type' => $type, 'remote_id' => strtoupper($type).'-'.bin2hex(random_bytes(3)),
            'job_id' => $jobId, 'amount' => $amount, 'status' => $status, 'starts_at' => $day.' 10:00:00', 'payload' => $payload, 'synced_at' => now(),
        ]));
    }
}
