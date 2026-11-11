<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\PhoneCall;
use App\Models\PhoneCallTag;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    /**
     * Sending a text IS an automation action now, and the engine performs it.
     *
     * This test previously asserted the opposite. That was correct while the
     * engine could not run it — a declared action nothing executes is a menu
     * entry that does nothing — and it is wrong now, so it is replaced rather
     * than deleted: the catalogue has to agree with ActionRunner in BOTH
     * directions, and the pairing is what this file exists to hold.
     */
    public function test_sending_a_text_is_offered_and_marked_implemented(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Phone rules', [
            // `automations.build.view` since 2026-09-17: the catalogue is the
            // build tab's own endpoint and now answers to that tab's grant.
            'automations.view', 'automations.build.view', 'phone.view',
        ]);

        $catalogue = $this->signedInAs($user)
            ->getJson('/api/v1/automations/catalogue')
            ->assertOk();

        $module = collect($catalogue->json('data.modules'))->firstWhere('key', 'phone');
        $this->assertNotNull($module);

        $action = collect($module['declared_actions'])->firstWhere('key', 'phone.sms.send');
        $this->assertNotNull($action, 'Send a message is not offered on the Phone module');
        $this->assertSame('Send a message', $action['label']);

        // Declared AND runnable. The catalogue marks the gap between what a
        // module offers and what the engine can do, and an action sitting in
        // that gap is exactly what the old version of this test guarded.
        $this->assertContains(
            'sms.send',
            collect($catalogue->json('data.action_types'))->pluck('key'),
            'Send a message is offered but the engine cannot run it',
        );
    }

    /**
     * A line that has not been cleared for messaging refuses, and says so.
     *
     * Twilio would refuse it anyway — US A2P traffic needs an approved
     * campaign — but it would refuse into the carrier, where nobody sees it.
     * The run log is the only place a rule's failure is visible, so an
     * unapproved line has to fail HERE with the reason.
     */
    public function test_a_line_not_cleared_for_messaging_refuses_with_the_reason(): void
    {
        $t = $this->provisionTenant();

        $result = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553100');
            $number->forceFill(['sms_outbound_allowed' => false])->save();

            $event = AutomationEvent::create([
                'event_key' => 'email.received',
                'subject_type' => null,
                'subject_id' => null,
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(
                ['type' => 'sms.send', 'number_id' => $number->id,
                    'to' => '+13057242199', 'body' => 'The van is here.'],
                $event,
                null,
            );
        });

        $this->assertFalse($result['ok']);
        $this->assertSame(PhoneNumber::SMS_REFUSAL, $result['detail']);

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0,
            PhoneMessage::query()->count(),
            'a text was recorded for a line that cannot send one',
        ));
    }

    /**
     * The ordinary case: an approved line sends, and the text is recorded.
     *
     * The trigger here is deliberately NOT a phone event. That is the whole
     * reason this action exists — a rule on an email arriving has no phone
     * record to work from, so the line and the destination come from the rule
     * and the subject is never read.
     */
    public function test_an_approved_line_sends_and_records_the_message(): void
    {
        $t = $this->provisionTenant();

        [$result, $sent] = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553110');
            $number->forceFill(['sms_outbound_allowed' => true])->save();

            $event = AutomationEvent::create([
                'event_key' => 'email.received',
                'subject_type' => null,
                'subject_id' => null,
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            $result = app(ActionRunner::class)->run(
                ['type' => 'sms.send', 'number_id' => $number->id,
                    'to' => '+13057242199', 'body' => 'A new email arrived.'],
                $event,
                null,
            );

            return [$result, PhoneMessage::query()->first()];
        });

        $this->assertTrue($result['ok'], $result['detail']);

        $this->assertNotNull($sent, 'the text was sent but never recorded');
        $this->assertSame('+12035553110', $sent->from_e164);
        $this->assertSame('+13057242199', $sent->to_e164);
        // The first text to a number carries the opt-out line (SmsConsent).
        $this->assertSame("A new email arrived.\n\n".\App\Support\Phone\SmsConsent::FOOTER, $sent->body);
    }

    public function test_phone_actions_are_marked_implemented_in_the_catalogue(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Phone catalogue', [
            // `automations.build.view` since 2026-09-17: the catalogue is the
            // build tab's own endpoint and now answers to that tab's grant.
            'automations.view', 'automations.build.view', 'phone.view',
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
     * @param  array{org: Organization, franchise: Franchise}  $tenant
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

    /**
     * Tagging a call raises an event a rule can act on, carrying the TAG NAME.
     *
     * ── Why this had to exist ────────────────────────────────────
     *
     * A tag became compulsory before somebody could hang up, and nothing was
     * raised when one was set. So the most useful moment in the module — the
     * instant a call stops being "a call" and becomes "a complaint" — reached
     * Automations not at all. The field was mandatory to fill in and inert
     * once filled: a report input, not an event.
     *
     * ── Why the name and not just the id ────────────────────────
     *
     * A rule is written by a person choosing "Complaint" from a list. An event
     * carrying only `tag_id` forces every condition to be written against a
     * uuid, which nobody does, so the trigger would have existed and been
     * unusable — the shape §8 keeps warning about.
     *
     * The franchise scope is asserted too, because a tagged call has to reach
     * the branch's own rules and not the branch next door's, and that answer
     * comes from PhoneCall::automationScope().
     */
    public function test_tagging_a_call_raises_an_event_carrying_the_tag_name(): void
    {
        $t = $this->provisionTenant();
        $agent = $this->userWithRole($t, 'Dispatch', ['phone.view'], 'tagger@t.test');

        [$call, $tag] = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553055', franchiseScoped: true);

            $tag = PhoneCallTag::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Complaint',
                'color' => '#B3261E',
                'is_active' => true,
                'position' => 1,
            ]);

            $call = PhoneCall::create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+15551110055',
                'to_e164' => '+12035553055',
                'status' => PhoneCall::STATUS_COMPLETED,
            ]);

            return [$call, $tag];
        });

        $this->signedInAs($agent)
            ->patchJson("/api/v1/phone/calls/{$call->id}/tag", ['tag_id' => $tag->id])
            ->assertOk();

        $this->inTenant($t['org'], function () use ($call, $t) {
            $event = AutomationEvent::query()
                ->where('event_key', 'phone.call.tagged')
                ->where('subject_id', $call->id)
                ->first();

            $this->assertNotNull($event, 'tagging a call raised nothing');

            $this->assertSame(
                'Complaint',
                $event->payload['after']['tag'] ?? null,
                'the event carried no tag name, so a rule could only be written '
                .'against a uuid',
            );

            // The branch's own rules, and nobody else's.
            $this->assertSame(
                ['franchise', $t['franchise']->id],
                $call->fresh('number')->automationScope(),
            );
        });
    }

    /**
     * Clearing a tag raises nothing.
     *
     * "This turned out not to be a complaint" is not something anybody writes
     * a rule against, and firing on the way out would run the complaint rule
     * a second time as the tag was removed.
     */
    public function test_clearing_a_tag_raises_nothing(): void
    {
        $t = $this->provisionTenant();
        $agent = $this->userWithRole($t, 'Dispatch', ['phone.view'], 'untagger@t.test');

        $call = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035553056');

            return PhoneCall::create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+15551110056',
                'to_e164' => '+12035553056',
                'status' => PhoneCall::STATUS_COMPLETED,
            ]);
        });

        $this->signedInAs($agent)
            ->patchJson("/api/v1/phone/calls/{$call->id}/tag", ['tag_id' => null])
            ->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertFalse(
            AutomationEvent::query()
                ->where('event_key', 'phone.call.tagged')
                ->where('subject_id', $call->id)
                ->exists(),
            'removing a tag raised a tagged event',
        ));
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
    private function signedPost(string $url, array $params, string $authToken): TestResponse
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
