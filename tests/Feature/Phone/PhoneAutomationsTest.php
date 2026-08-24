<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Phone automation actions and phone.number.broken (Task 14).
 *
 * Mark voicemail dealt and open a job from a missed call change EzBizi only.
 * Sending SMS is not an ActionRunner action. A gateway failure marks the line
 * broken once and raises phone.number.broken on that transition.
 */
class PhoneAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_mark_dealt_sets_dealt_at_on_a_voicemail(): void
    {
        $t = $this->provisionTenant();

        $call = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553001', franchiseScoped: true);

            return PhoneCall::create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+15551110001',
                'to_e164' => '+12035553001',
                'status' => PhoneCall::STATUS_NO_ANSWER,
                'voicemail' => true,
            ]);
        });

        $result = $this->inTenant($t['org'], function () use ($call) {
            $event = AutomationEvent::create([
                'event_key' => 'phone.voicemail.left',
                'subject_type' => PhoneCall::class,
                'subject_id' => $call->id,
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(
                ['type' => 'voicemail.mark_dealt'],
                $event,
                $call->fresh(),
            );
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('voicemail.mark_dealt', $result['type']);

        $this->inTenant($t['org'], function () use ($call) {
            $row = PhoneCall::query()->find($call->id);
            $this->assertNotNull($row->dealt_at);
        });
    }

    public function test_create_job_from_a_missed_call_titles_from_the_caller_number(): void
    {
        $t = $this->provisionTenant();
        $caller = '+15551110002';

        $call = $this->inTenant($t['org'], function () use ($t, $caller) {
            [, $number] = $this->openLine($t, e164: '+12035553002', franchiseScoped: true);

            return PhoneCall::create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => $caller,
                'to_e164' => '+12035553002',
                'status' => PhoneCall::STATUS_NO_ANSWER,
            ]);
        });

        $result = $this->inTenant($t['org'], function () use ($call) {
            $event = AutomationEvent::create([
                'event_key' => 'phone.call.missed',
                'subject_type' => PhoneCall::class,
                'subject_id' => $call->id,
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(
                ['type' => 'missed.create_job'],
                $event,
                $call->fresh(),
            );
        });

        $this->assertTrue($result['ok'], $result['detail'] ?? '');
        $this->assertSame('missed.create_job', $result['type']);

        $this->inTenant($t['org'], function () use ($t, $caller, $call) {
            $job = Job::query()->first();
            $this->assertNotNull($job);
            $this->assertSame($caller, $job->title);
            $this->assertSame($t['franchise']->id, $job->franchise_id);

            $customer = Customer::query()->find($job->customer_id);
            $this->assertNotNull($customer);
            $this->assertSame($caller, $customer->phone);

            $this->assertTrue(
                AutomationEvent::query()->where('event_key', 'job.created')->exists(),
            );
            $this->assertSame($call->id, PhoneCall::query()->find($call->id)?->id);
        });
    }

    public function test_sending_sms_is_not_an_implemented_automation_action(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Phone rules', [
            'automations.view', 'phone.view',
        ]);

        $module = collect(
            $this->signedInAs($user)
                ->getJson('/api/v1/automations/catalogue')
                ->assertOk()
                ->json('data.modules')
        )->firstWhere('key', 'phone');

        $this->assertNotNull($module);

        $keys = collect($module['declared_actions'])->pluck('key');
        $this->assertNotContains('phone.sms.send', $keys);
        $this->assertNotContains('phone.send_sms', $keys);

        foreach ($module['declared_actions'] as $action) {
            $this->assertStringNotContainsString('send a text', strtolower($action['label']));
            $this->assertStringNotContainsString('send sms', strtolower($action['label']));
        }

        $types = collect(
            $this->signedInAs($user)
                ->getJson('/api/v1/automations/catalogue')
                ->assertOk()
                ->json('data.action_types')
        )->pluck('key');

        $this->assertNotContains('sms.send', $types);
        $this->assertNotContains('send_sms', $types);
    }

    public function test_phone_actions_are_marked_implemented_in_the_catalogue(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Phone catalogue', [
            'automations.view', 'phone.view',
        ]);

        $module = collect(
            $this->signedInAs($user)
                ->getJson('/api/v1/automations/catalogue')
                ->assertOk()
                ->json('data.modules')
        )->firstWhere('key', 'phone');

        $byKey = collect($module['declared_actions'])->keyBy('key');

        $this->assertTrue($byKey['phone.voicemail.mark_dealt']['implemented']);
        $this->assertTrue($byKey['phone.missed.create_job']['implemented']);
    }

    public function test_gateway_failure_raises_number_broken_once_on_transition(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $viewer) {
            [$connection, $number] = $this->openLine($t, e164: '+12035553003');
            $number->update(['sms_outbound_allowed' => true]);
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $viewer->role_id,
            ]);

            return [$connection, $number->fresh()];
        });

        $fake = $this->app->make(TwilioGateway::class);
        $this->assertInstanceOf(FakeTwilioGateway::class, $fake);
        $fake->failSendMessageWith = 'The Twilio account for this line is suspended.';

        $this->signedInAs($viewer)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15550001111',
            'body' => 'Will not leave.',
        ])->assertStatus(502);

        $this->inTenant($t['org'], function () use ($connection, $number) {
            $row = PhoneConnection::query()->find($connection->id);
            $this->assertSame(PhoneConnection::STATUS_BROKEN, $row->status);
            $this->assertNotSame('', (string) $row->status_reason);

            $events = AutomationEvent::query()
                ->where('event_key', 'phone.number.broken')
                ->get();
            $this->assertCount(1, $events);
            $this->assertTrue(
                in_array($events[0]->subject_type, [PhoneNumber::class, PhoneConnection::class], true),
            );
            $this->assertContains($events[0]->subject_id, [$number->id, $connection->id]);
        });

        $this->signedInAs($viewer)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15550001111',
            'body' => 'Still will not leave.',
        ])->assertStatus(502);

        $this->inTenant($t['org'], function () {
            $this->assertSame(
                1,
                AutomationEvent::query()->where('event_key', 'phone.number.broken')->count(),
            );
        });
    }

    public function test_number_broken_is_raised(): void
    {
        $module = app(ModuleRegistry::class)->get('phone');

        $this->assertTrue($module->trigger('phone.number.broken')->isRaised);
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber}
     */
    private function openLine(array $tenant, string $e164, bool $franchiseScoped = false): array
    {
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => 'auto-auth-'.$e164,
            'status' => PhoneConnection::STATUS_CONNECTED,
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => $franchiseScoped
                ? PhoneNumber::SCOPE_FRANCHISE
                : PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $franchiseScoped ? $tenant['franchise']->id : $tenant['org']->id,
            'e164' => $e164,
            'label' => 'Line '.$e164,
        ]);

        return [$connection, $number];
    }
}
