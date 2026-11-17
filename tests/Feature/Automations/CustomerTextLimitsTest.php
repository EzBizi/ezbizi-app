<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\PhoneConnection;
use App\Support\Automations\AutomationEngine;
use App\Support\Phone\SmsCapped;
use App\Support\Phone\SmsConsent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk for Jose, 2026-11-16: no automatic customer text at 3 AM, and at most two a day. */
class CustomerTextLimitsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_customer_text_due_at_night_waits_for_8_am_where_the_branch_is(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $zone = (string) app(\App\Support\Settings\SettingsResolver::class)->get('timezone', $t['franchise']);
            $scope = ['type' => 'franchise', 'id' => $t['franchise']->id];
            $text = new AutomationVersion(['actions' => [['type' => 'reply', 'message' => 'Hi']]]);
            $staff = new AutomationVersion(['actions' => [['type' => 'sms.send', 'to' => 'user:x', 'body' => 'Hi']]]);
            $missed = new AutomationEvent(['event_key' => 'lead.missed_call']);
            $at = fn (string $local) => CarbonImmutable::parse($local, $zone)->utc();

            $held = AutomationEngine::outsideQuietHours($at('2026-11-17 03:00'), $text, $missed, $scope);
            $this->assertSame('2026-11-17 08:00', CarbonImmutable::instance($held)->setTimezone($zone)->format('Y-m-d H:i'));
            $late = AutomationEngine::outsideQuietHours($at('2026-11-17 22:30'), $text, $missed, $scope);
            $this->assertSame('2026-11-18 08:00', CarbonImmutable::instance($late)->setTimezone($zone)->format('Y-m-d H:i'));

            $day = $at('2026-11-17 14:00');
            $this->assertTrue($day->eq(AutomationEngine::outsideQuietHours($day, $text, $missed, $scope)), 'daytime goes now');
            $night = $at('2026-11-17 03:00');
            $this->assertTrue($night->eq(AutomationEngine::outsideQuietHours($night, $staff, $missed, $scope)), 'a text to staff is not held');
            $this->assertTrue($night->eq(AutomationEngine::outsideQuietHours($night, $text, new AutomationEvent(['event_key' => 'lead.received']), $scope)),
                'the customer just wrote: answered at once');
        });
    }

    /** Jose, 2026-11-17: Noorzi got Not booked AND Missed call; only the missed-call text is right. */
    public function test_a_not_booked_text_yields_to_a_missed_call_and_to_a_recent_customer(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $lead = \App\Models\Lead::create(['organization_id' => $t['org']->id, 'source' => \App\Models\Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'n1', 'contact_name' => 'Pat', 'lead_type' => 'PHONE_CALL', 'status' => \App\Models\Lead::STATUS_NEW,
                'received_at' => now(), 'phone' => '+15108279735']);
            $notBooked = new AutomationEvent(['event_key' => 'lead.call_not_booked']);
            $this->assertNull(\App\Support\Automations\ActionRunner::notBookedSkip($notBooked, $lead), 'a plain caller still gets it');
            $this->assertNull(\App\Support\Automations\ActionRunner::notBookedSkip(new AutomationEvent(['event_key' => 'lead.missed_call']), $lead));

            $missed = AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => 'lead.missed_call', 'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id, 'payload' => [], 'occurred_at' => now()->subHours(3), 'available_at' => now()]);
            $this->assertStringContainsString('missed call', (string) \App\Support\Automations\ActionRunner::notBookedSkip($notBooked, $lead));
            $missed->forceFill(['occurred_at' => now()->subDays(2)])->save();
            $this->assertNull(\App\Support\Automations\ActionRunner::notBookedSkip($notBooked, $lead), 'an old missed call does not count');

            // The other order: a Not-booked text went today, so a later missed call is not texted.
            $nb = AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => 'lead.call_not_booked', 'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id, 'payload' => [], 'occurred_at' => now()->subHours(5), 'available_at' => now()]);
            $rule = \App\Models\Automation::create(['name' => 'NB', 'trigger_key' => 'lead.call_not_booked', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $run = \App\Models\AutomationRun::create(['organization_id' => $t['org']->id, 'automation_id' => $rule->id, 'automation_version_id' => $rule->versions()->create(['organization_id' => $t['org']->id, 'version' => 1, 'actions' => []])->id, 'event_id' => $nb->id, 'status' => 'succeeded',
                'action_results' => [['type' => 'reply', 'ok' => true, 'skipped' => true]], 'started_at' => now()->subHours(3)]);
            $later = new AutomationEvent(['event_key' => 'lead.missed_call']);
            $this->assertNull(\App\Support\Automations\ActionRunner::notBookedSkip($later, $lead), 'a skipped Not-booked text is no text');
            $run->forceFill(['action_results' => [['type' => 'reply', 'ok' => true]]])->save();
            $this->assertStringContainsString('already had a text', (string) \App\Support\Automations\ActionRunner::notBookedSkip($later, $lead));

            $customer = \App\Models\Customer::factory()->create(['phone' => '(510) 827-9735', 'owning_franchise_id' => $t['franchise']->id]);
            \App\Models\Job::factory()->create(['customer_id' => $customer->id, 'franchise_id' => $t['franchise']->id]);
            $this->assertStringContainsString('customer', (string) \App\Support\Automations\ActionRunner::notBookedSkip(
                new AutomationEvent(['event_key' => 'lead.follow_up_due']), $lead));
        });
    }

    public function test_a_number_gets_two_automatic_texts_a_day_and_a_capped_send_is_refused(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create(['scope_type' => 'organization', 'scope_id' => $t['org']->id]);
            $to = '+13055550101';
            $sent = fn (bool $automatic = true, $at = null) => DB::table('phone_messages')->insert(['id' => (string) Str::uuid(),
                'organization_id' => $t['org']->id, 'connection_id' => $connection->id, 'direction' => 'outbound', 'twilio_sid' => 'SM'.Str::random(32),
                'from_e164' => '+18559553322', 'to_e164' => $to, 'body' => $automatic ? "Hi

".SmsConsent::FOOTER : 'A person wrote this',
                'created_at' => $at ?? now(), 'updated_at' => now()]);

            $sent(at: now()->subDays(2));
            $sent(false);
            $sent();
            $this->assertNotNull(SmsConsent::prepare($connection, $to, 'Hi', automated: true, capped: true), 'one today, a person\'s and an old one do not count');
            $sent();
            $this->assertNotNull(SmsConsent::prepare($connection, $to, 'Hi', automated: true), 'uncapped callers (a booking confirmation) still send');

            $this->expectException(SmsCapped::class);
            SmsConsent::prepare($connection, $to, 'Hi', automated: true, capped: true);
        });
    }
}
