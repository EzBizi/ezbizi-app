<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** EzBizi is the master (Jose, 2026-10-27): the job itself is set, with or without Workiz. */
class JobSetStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_sets_the_jobs_own_status_and_replaces_its_tags_without_workiz(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $customer = Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id]);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id,
                'tags' => ['Old tag'], 'scheduled_for' => now()->addDay()]);
            $status = Job::statusEntries($t['org'])[0]['value'];
            $event = AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => 'job.payment_received', 'payload' => [],
                'occurred_at' => now(), 'available_at' => now()]);

            $done = app(ActionRunner::class)->run(['type' => 'job.set', 'status' => $status,
                'tags' => 'Deposit Received, Dont Change Tech', 'unschedule' => true], $event, $job);

            $this->assertTrue($done['ok'], $done['detail']);
            $job = $job->fresh();
            $this->assertSame($status, $job->status);
            $this->assertSame(['Deposit Received', 'Dont Change Tech'], $job->tags);
            $this->assertNull($job->scheduled_for);
            $this->assertStringNotContainsString('Workiz', $done['detail'], 'no Workiz connected, so none was touched');
        });
    }
}
