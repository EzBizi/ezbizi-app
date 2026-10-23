<?php

namespace Tests\Feature\Reviews;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Support\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Review replies are paced (A1 Leads takeover, 2026-10-23): a rule that
 * publishes a reply waits between its wait and twice it, per review, so one
 * sync's batch is not answered in the same minute. Other rules wait as set.
 */
class ReviewReplyPacingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function rule(array $t, array $actions): void
    {
        $this->inTenant($t['org'], function () use ($t, $actions) {
            $a = Automation::create(['organization_id' => $t['org']->id, 'name' => 'R', 'trigger_key' => 'review.received',
                'is_active' => true, 'delay_minutes' => 60]);
            $v = AutomationVersion::create(['organization_id' => $t['org']->id, 'automation_id' => $a->id, 'version' => 1,
                'conditions' => null, 'actions' => $actions, 'created_at' => now()]);
            $a->forceFill(['active_version_id' => $v->id])->save();
        });
    }

    /** @return list<int> minutes from the review to when each event comes due */
    private function waits(array $t, int $events): array
    {
        return collect(range(1, $events))->map(function () use ($t) {
            $event = $this->inTenant($t['org'], fn () => AutomationEvent::create(['organization_id' => $t['org']->id,
                'event_key' => 'review.received', 'payload' => [], 'occurred_at' => now(), 'available_at' => now()]));
            $this->inTenant($t['org'], fn () => app(AutomationEngine::class)->deliver($event));
            $this->inTenant($t['org'], fn () => app(AutomationEngine::class)->deliver($event->fresh())); // redelivery: same due time

            return (int) round($event->occurred_at->diffInMinutes($event->fresh()->available_at, true));
        })->all();
    }

    public function test_a_reply_rule_spreads_a_batch_between_its_wait_and_twice_it(): void
    {
        $t = $this->provisionTenant();
        $this->rule($t, [['type' => 'publish_reply', 'ai' => true]]);

        $waits = $this->waits($t, 12);
        foreach ($waits as $w) {
            $this->assertGreaterThanOrEqual(60, $w);
            $this->assertLessThanOrEqual(120, $w);
        }
        $this->assertGreaterThan(3, count(array_unique($waits)), 'a batch is spread, not posted together');
    }

    public function test_any_other_rule_waits_exactly_as_set(): void
    {
        $t = $this->provisionTenant();
        $this->rule($t, [['type' => 'notify', 'message' => 'x']]);

        $this->assertSame([60, 60, 60], $this->waits($t, 3));
    }
}
