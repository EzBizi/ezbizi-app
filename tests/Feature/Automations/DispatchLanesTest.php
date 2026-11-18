<?php

namespace Tests\Feature\Automations;

use App\Jobs\DeliverAutomationEvent;
use App\Models\AutomationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk, 2026-11-18: a 9,314-event backfill held missed calls 20 minutes on the one default queue. */
class DispatchLanesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function event(array $t, string $key, ?string $subject = null, int $minutesAgo = 1): AutomationEvent
    {
        return $this->inTenant($t['org'], fn () => AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => $key,
            'subject_type' => $subject ? 'lead' : null, 'subject_id' => $subject, 'payload' => [],
            'occurred_at' => now()->subMinutes($minutesAgo), 'available_at' => now()->subMinutes($minutesAgo)]));
    }

    public function test_events_a_person_waits_on_go_to_the_realtime_queue(): void
    {
        config(['queue.automations_realtime' => 'realtime']);
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $missed = $this->event($t, 'reports.call.missed');
        $bulk = $this->event($t, 'task.column_changed');

        $this->artisan('ezbizi:dispatch-automation-events')->assertSuccessful();

        Queue::assertPushedOn('realtime', DeliverAutomationEvent::class, fn ($j) => $j->eventId === $missed->id);
        Queue::assertPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $bulk->id && $j->queue !== 'realtime');
    }

    public function test_a_records_later_event_waits_for_its_earlier_one(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $lead = (string) Str::uuid();
        $first = $this->event($t, 'lead.received', $lead, 5);
        $second = $this->event($t, 'lead.customer_replied', $lead, 2);

        $this->artisan('ezbizi:dispatch-automation-events')->assertSuccessful();

        Queue::assertPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $first->id);
        Queue::assertNotPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $second->id);

        AutomationEvent::withoutGlobalScopes()->whereKey($first->id)->update(['delivered_at' => now()]);
        $this->artisan('ezbizi:dispatch-automation-events')->assertSuccessful();
        Queue::assertPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $second->id);
    }

    public function test_a_delayed_earlier_event_does_not_hold_a_later_one(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $lead = (string) Str::uuid();
        $delayed = $this->event($t, 'lead.follow_up_due', $lead, 30);
        AutomationEvent::withoutGlobalScopes()->whereKey($delayed->id)->update(['available_at' => now()->addHour()]);
        $reply = $this->event($t, 'lead.customer_replied', $lead, 1);

        $this->artisan('ezbizi:dispatch-automation-events')->assertSuccessful();

        Queue::assertPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $reply->id);
    }

    public function test_an_earlier_event_stuck_over_15_minutes_stops_blocking(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $lead = (string) Str::uuid();
        $stuck = $this->event($t, 'lead.received', $lead, 40);
        AutomationEvent::withoutGlobalScopes()->whereKey($stuck->id)->update(['queued_at' => now()]); // in flight, not re-queued
        $later = $this->event($t, 'lead.customer_replied', $lead, 1);

        $this->artisan('ezbizi:dispatch-automation-events')->assertSuccessful();

        Queue::assertPushed(DeliverAutomationEvent::class, fn ($j) => $j->eventId === $later->id);
    }
}
