<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Notification;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** "Mark as urgent (red)" on a notify step (Jose, 2026-10-15: the complaint call icon red). */
class UrgentNotifyStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_urgent_step_marks_its_rows_and_a_plain_one_does_not(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $rule = Automation::create(['name' => 'Complaint call', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.received', 'payload' => []]);
            $runner = app(ActionRunner::class);

            $runner->run(['type' => 'notify', 'message' => 'Urgent one', 'user_ids' => [$t['admin']->id], 'urgent' => true], $event, null, automation: $rule);
            $runner->run(['type' => 'notify', 'message' => 'Plain one', 'user_ids' => [$t['admin']->id]], $event, null, automation: $rule);

            $this->assertSame(['Plain one' => false, 'Urgent one' => true], Notification::query()->orderBy('title')->pluck('urgent', 'title')->all());
        });
    }
}
