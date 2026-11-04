<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13: "a supervisor was assigned" and booking confirmations, texted onto the enquiry. */
class LeadTextStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_texts_the_lead_onto_its_thread_and_skips_one_with_no_phone(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+12035551004', 'sms_outbound_allowed' => true]);
            $lead = fn (?string $phone) => Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'lead_type' => 'PHONE_CALL', 'remote_id' => uniqid(), 'phone' => $phone, 'status' => 'new', 'received_at' => now(),
                'contact_name' => 'Pat']);
            $rule = Automation::create(['name' => 'Complaint call', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.received', 'payload' => ['after' => ['contact_name' => 'Pat']]]);
            $runner = app(ActionRunner::class);

            $withPhone = $lead('+15553334444');
            $sent = $runner->run(['type' => 'lead.text', 'body' => 'Thanks {{contact_name}}, a supervisor has been assigned.'], $event, $withPhone, automation: $rule);
            $this->assertTrue($sent['ok'], $sent['detail']);
            // An automated text always carries the opt-out line (SmsConsent).
            $this->assertSame(['Thanks Pat, a supervisor has been assigned.'."\n".\App\Support\Phone\SmsConsent::FOOTER], LeadConversation::query()->where('lead_id', $withPhone->id)->pluck('text')->all());

            $none = $runner->run(['type' => 'lead.text', 'body' => 'Hi'], $event, $lead(null), automation: $rule);
            $this->assertTrue($none['skipped'] ?? false, 'no phone is skipped, not a failure toward switching the rule off');
        });
    }

    /** Desk, 2026-11-04: 71 of 125 "After a call" replies skipped because Google takes no text on a call. */
    public function test_a_reply_to_a_google_call_goes_by_text(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'e164' => '+12035551004', 'sms_outbound_allowed' => true]);
            $call = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'lead_type' => 'PHONE_CALL', 'remote_id' => uniqid(), 'phone' => '+15553334444', 'status' => 'new', 'received_at' => now(),
                'contact_name' => 'Pat']);
            $event = AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => 'lead.call_not_booked',
                'payload' => ['after' => ['contact_name' => 'Pat']], 'occurred_at' => now(), 'available_at' => now()]);

            $sent = app(ActionRunner::class)->run(['type' => 'reply', 'message' => 'Sorry we missed you.'], $event, $call);

            $this->assertTrue($sent['ok'], $sent['detail']);
            $this->assertFalse($sent['skipped'] ?? false);
            $this->assertSame(['SMS'], LeadConversation::query()->where('lead_id', $call->id)->pluck('channel')->all());
        });
    }
}
