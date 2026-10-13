<?php

namespace Tests\Feature\Automations;

use App\Models\Alert;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\Notification;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-13: complaint calls also pop up as a Communication › Alert,
 * and an auto reply's "Book online now" line goes only when there is a page.
 */
class AlertStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_sends_a_pop_up_alert_like_a_person_and_needs_the_grant(): void
    {
        $t = $this->provisionTenant('Alpha');
        $one = $this->userWithRole($t, 'Viewer', ['tasks.view']);
        $two = $this->userWithRole($t, 'Clerk', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $one, $two) {
            $rule = Automation::create(['name' => 'Complaint call', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.received', 'payload' => ['after' => ['phone' => '+15550001111']]]);
            $runner = app(ActionRunner::class);

            $sent = $runner->run(['type' => 'alert.send', 'user_ids' => [$one->id, $two->id, 'gone-user-id'], 'message' => 'Complaint call {{phone}}'], $event, null, automation: $rule);

            $this->assertTrue($sent['ok'], $sent['detail']);
            $this->assertStringContainsString('1 named person is no longer active here', $sent['detail']);
            $alert = Alert::query()->sole();
            $this->assertSame('Complaint call +15550001111', $alert->message);
            $this->assertEqualsCanonicalizing([$one->id, $two->id], Notification::query()->where('source', 'alert')->pluck('user_id')->all());

            // A rule made by somebody who may not send alerts by hand sends none.
            $theirs = Automation::create(['name' => 'X', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $one->id]);
            $refused = $runner->run(['type' => 'alert.send', 'user_ids' => [$two->id], 'message' => 'Hi'], $event, null, automation: $theirs);
            $this->assertFalse($refused['ok']);
            $this->assertSame(1, Alert::query()->count());
        });
    }

    public function test_the_booking_line_is_dropped_when_the_branch_has_no_booking_page(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $lead = Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'source' => Lead::SOURCE_WEBSITE_CHAT, 'remote_id' => 'chat-1', 'status' => Lead::STATUS_NEW, 'received_at' => now()]);
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);

            $told = app(ActionRunner::class)->run(['type' => 'notify', 'message' => 'Closed', 'details' => "We are closed.\nBook online now: {{booking_link}}\nTalk soon.",
                'user_ids' => [$t['admin']->id]], new AutomationEvent(['event_key' => 'lead.received', 'payload' => []]), $lead, automation: $rule);

            $this->assertTrue($told['ok'], $told['detail']);
            $this->assertSame("We are closed.\nTalk soon.", Notification::query()->sole()->body);
        });
    }
}
