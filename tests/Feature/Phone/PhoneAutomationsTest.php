<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\PhoneMessage;
use App\Support\Notifications\NotificationDispatcher;
use App\Models\PhoneNumberGrant;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

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

    public function test_recipient_fault_on_outbound_sms_does_not_break_the_line(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk recipient', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $viewer) {
            [$connection, $number] = $this->openLine($t, e164: '+12035553004');
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
        $fake->failSendMessageCode = 21211;

        $this->signedInAs($viewer)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15550009999',
            'body' => 'Bad destination.',
        ])->assertStatus(502);

        $this->inTenant($t['org'], function () use ($connection) {
            $row = PhoneConnection::query()->find($connection->id);
            $this->assertSame(PhoneConnection::STATUS_CONNECTED, $row->status);
            $this->assertSame(
                0,
                AutomationEvent::query()->where('event_key', 'phone.number.broken')->count(),
            );
        });
    }

    public function test_status_webhook_invalid_to_does_not_break_the_line(): void
    {
        $t = $this->provisionTenant();
        $authToken = 'auto-auth-+12035553005';

        [$connection] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, $number] = $this->openLine($t, e164: '+12035553005');
            PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                'direction' => PhoneCall::DIRECTION_OUTBOUND,
                'from_e164' => '+12035553005',
                'to_e164' => '+15550008888',
                'status' => PhoneCall::STATUS_RINGING,
            ]);

            return [$connection, $number];
        });

        $this->signedPost($this->statusUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CAeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'CallStatus' => 'failed',
            'ErrorCode' => '21211',
            'ErrorMessage' => 'Invalid To phone number',
        ], $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($connection) {
            $row = PhoneConnection::query()->find($connection->id);
            $this->assertSame(PhoneConnection::STATUS_CONNECTED, $row->status);
            $this->assertSame(
                0,
                AutomationEvent::query()->where('event_key', 'phone.number.broken')->count(),
            );
        });
    }

    public function test_status_webhook_account_fault_breaks_the_line_once(): void
    {
        $t = $this->provisionTenant();
        $authToken = 'auto-auth-+12035553006';

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, $number] = $this->openLine($t, e164: '+12035553006');
            PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAffffffffffffffffffffffffffffffff',
                'direction' => PhoneCall::DIRECTION_OUTBOUND,
                'from_e164' => '+12035553006',
                'to_e164' => '+15550007777',
                'status' => PhoneCall::STATUS_RINGING,
            ]);

            return [$connection, $number];
        });

        $params = [
            'CallSid' => 'CAffffffffffffffffffffffffffffffff',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'CallStatus' => 'failed',
            'ErrorCode' => '20003',
            'ErrorMessage' => 'Authenticate',
        ];
        $url = $this->statusUrl($t['org']->id, $connection->id);

        $this->signedPost($url, $params, $authToken)->assertOk();

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

        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $this->assertSame(
                1,
                AutomationEvent::query()->where('event_key', 'phone.number.broken')->count(),
            );
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber}
     */
    /**
     * A customer's message is not announced to a company that does not answer
     * that line.
     *
     * NotificationDispatcher works out its audience by sniffing `franchise_id`
     * and `owning_franchise_id`. `phone_messages` carries NEITHER, so the sniff
     * returned null, the dispatcher read that as "belongs to the whole
     * organization", and took the branch that tells EVERY ACTIVE USER.
     *
     * That branch is reachable from a tenant-authored rule: there are five
     * phone triggers, and on `phone.sms.received` what it would have carried to
     * the whole business is what a customer wrote. Nothing failed while it did
     * so - the rule ran, the notification arrived, and it arrived to everybody.
     *
     * The outsider below holds every phone permission there is and works at
     * another branch. Reach, not permission, is what has to exclude them, which
     * is why giving them those permissions is part of the test rather than an
     * oversight.
     */
    public function test_a_notification_about_a_phone_message_reaches_only_people_who_can_open_it(): void
    {
        $t = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($t['org'], fn () => Franchise::create([
            'organization_id' => $t['org']->id,
            'company_id' => $t['company']->id,
            'name' => 'Alpha F2',
        ]));

        $outsider = $this->userWithRole(
            $t, 'Other branch', ['phone.view', 'phone.edit'],
            'other@alpha.test', onlyAt: $elsewhere,
        );

        $insider = $this->userWithRole(
            $t, 'This branch', ['phone.view'],
            'here@alpha.test', onlyAt: $t['franchise'],
        );

        $told = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553009', franchiseScoped: true);

            $message = PhoneMessage::create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'direction' => PhoneMessage::DIRECTION_INBOUND,
                'from_e164' => '+15551110009',
                'to_e164' => '+12035553009',
                'body' => 'Can somebody call me back about the chimney',
            ]);

            app(NotificationDispatcher::class)->dispatch(
                title: 'A new message',
                body: $message->body,
                subject: $message,
            );

            return Notification::query()->pluck('user_id')->all();
        });

        // Without this the last assertion passes on an empty audience, which is
        // the one way this test could certify silence as safety.
        $this->assertNotEmpty($told, 'Nobody was told at all, so this proves nothing.');
        $this->assertContains($insider->getKey(), $told,
            'The branch that answers the line was not told about its own message.');

        $this->assertNotContains(
            $outsider->getKey(),
            $told,
            'Somebody at another branch was sent what a customer wrote.',
        );
    }

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

    /** @param  array<string, string>  $params */
    private function signedPost(string $url, array $params, string $authToken): \Illuminate\Testing\TestResponse
    {
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);
    }

    private function statusUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/status/'.$organizationId.'/'.$connectionId);
    }
}
