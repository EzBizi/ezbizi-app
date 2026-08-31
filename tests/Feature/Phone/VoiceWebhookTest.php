<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Public Voice / status webhooks write call rows and raise automations (Task 10).
 *
 * Signature is still the gate. A valid inbound inserts a ringing PhoneCall,
 * returns TwiML, and emits phone.call.started in the same transaction.
 */
class VoiceWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_inbound_creates_a_call_row_and_returns_twiml(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551001');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            from: '+15551112222',
            to: '+12035551001',
        );

        $response = $this->signedPost($url, $params, $authToken);

        $response->assertOk();
        $this->assertSame('text/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $xml = $response->getContent();
        $this->assertStringContainsString('<Response', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('<Client>'.$identity.'</Client>', $xml);
        $this->assertStringContainsString('answerOnBridge="true"', $xml);
        $this->assertStringNotContainsString('VoiceWebhookController', $xml);
        $this->assertStringNotContainsString('PhoneCall', $xml);

        $call = $this->inTenant($t['org'], fn () => PhoneCall::query()->first());
        $this->assertNotNull($call);
        $this->assertSame('CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $call->twilio_sid);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('+15551112222', $call->from_e164);
        $this->assertSame('+12035551001', $call->to_e164);
        $this->assertSame('ringing', $call->status);
        $this->assertSame($number->id, $call->number_id);
        $this->assertSame($connection->id, $call->connection_id);
    }

    public function test_a_bad_signature_is_still_forbidden_and_writes_nothing(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], function () use ($t) {
            [$connection] = $this->openLine($t, e164: '+12035551002');

            return $connection;
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            from: '+15551112222',
            to: '+12035551002',
        );

        $this->post($url, $params, [
            'X-Twilio-Signature' => 'not-a-real-signature',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneCall::query()->count());
            $this->assertSame(0, AutomationEvent::query()->count());
        });
    }

    public function test_inbound_emits_phone_call_started_in_the_outbox(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551003');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAcccccccccccccccccccccccccccccccc',
            from: '+15550001111',
            to: '+12035551003',
        );

        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($number) {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);

            $event = AutomationEvent::query()
                ->where('event_key', 'phone.call.started')
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($call->id, $event->subject_id);
            $this->assertSame(PhoneCall::class, $event->subject_type);

            $this->assertSame(1, AutomationEvent::query()->count());
            $this->assertSame($number->id, $call->number_id);
        });
    }

    public function test_a_second_post_for_the_same_call_does_not_emit_again(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551004');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAdddddddddddddddddddddddddddddddd',
            from: '+15550002222',
            to: '+12035551004',
        );

        $this->signedPost($url, $params, $authToken)->assertOk();
        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, PhoneCall::query()->count());
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.started')
                ->count());
        });
    }

    public function test_dial_no_answer_requests_the_next_batch(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken, , $identities] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551005', desks: 11);
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            from: '+15550003333',
            to: '+12035551005',
        );

        $first = $this->signedPost($url, $params, $authToken);
        $first->assertOk();
        $xml = $first->getContent();

        $this->assertSame(10, $this->clientCount($xml));
        $this->assertStringContainsString('<Client>'.$identities[0].'</Client>', $xml);
        $this->assertStringNotContainsString('<Client>'.$identities[10].'</Client>', $xml);

        $action = $this->dialAction($xml);
        $this->assertNotSame('', $action);
        $this->assertStringContainsString('batch=', $action);

        $second = $this->signedPost($action, $params + [
            'DialCallStatus' => 'no-answer',
        ], $authToken);
        $second->assertOk();
        $next = $second->getContent();

        $this->assertSame(1, $this->clientCount($next));
        $this->assertStringContainsString('<Client>'.$identities[10].'</Client>', $next);
        $this->assertStringNotContainsString('Please leave a message after the tone.', $next);
    }

    public function test_dial_no_answer_after_the_last_batch_goes_to_voicemail_and_is_missed(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551006');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAffffffffffffffffffffffffffffffff',
            from: '+15550004444',
            to: '+12035551006',
        );

        $first = $this->signedPost($url, $params, $authToken);
        $first->assertOk();
        $action = $this->dialAction($first->getContent());
        $this->assertNotSame('', $action, 'The first Dial must ask Twilio for the next step.');

        $this->signedPost($action, $params + [
            'DialCallStatus' => 'no-answer',
        ], $authToken)
            ->assertOk()
            ->assertSee('Please leave a message after the tone.', false)
            ->assertSee('<Record', false);

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertEqualsCanonicalizing(['phone.call.started', 'phone.call.missed'], $keys);
        });
    }

    public function test_zero_available_inbound_goes_to_voicemail_and_is_missed(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551009', desks: 0);
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CA55555555555555555555555555555555',
            from: '+15550008888',
            to: '+12035551009',
        );

        $this->signedPost($url, $params, $authToken)
            ->assertOk()
            ->assertSee('Please leave a message after the tone.', false)
            ->assertSee('<Record', false)
            ->assertDontSee('<Dial', false);

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertEqualsCanonicalizing(['phone.call.started', 'phone.call.missed'], $keys);
        });
    }

    public function test_status_completed_after_voicemail_keeps_the_miss(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551010', desks: 0);
        });

        $params = $this->inboundParams(
            callSid: 'CA66666666666666666666666666666666',
            from: '+15550009999',
            to: '+12035551010',
        );
        $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), $params, $authToken)
            ->assertOk();

        $this->signedPost($this->statusUrl($t['org']->id, $connection->id), array_merge($params, [
            'CallStatus' => 'completed',
            'CallDuration' => '18',
        ]), $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);
            $this->assertSame(18, $call->duration_seconds);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertContains('phone.call.started', $keys);
            $this->assertContains('phone.call.missed', $keys);
            $this->assertContains('phone.call.ended', $keys);
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.missed')
                ->count());
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.ended')
                ->count());
        });
    }

    public function test_status_completed_stores_duration_and_emits_ended(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551007');
        });

        $voice = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CA11111111111111111111111111111111',
            from: '+15550005555',
            to: '+12035551007',
        );
        $this->signedPost($voice, $params, $authToken)->assertOk();

        $status = $this->statusUrl($t['org']->id, $connection->id);
        $this->signedPost($status, array_merge($params, [
            'CallStatus' => 'completed',
            'CallDuration' => '42',
        ]), $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('completed', $call->status);
            $this->assertSame(42, $call->duration_seconds);

            $keys = AutomationEvent::query()->orderBy('occurred_at')->pluck('event_key')->all();
            $this->assertContains('phone.call.started', $keys);
            $this->assertContains('phone.call.ended', $keys);
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.ended')
                ->count());
        });
    }

    public function test_outbound_from_the_twiml_app_skips_hours_and_ivr(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, $number, $authToken, $identity] = $this->openLine($t, e164: '+12035551008');
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Never open',
                'timezone' => 'America/New_York',
                'weeks' => [],
                'exceptions' => null,
            ]);
            $flow = PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk.',
                ],
            ]);
            $number->forceFill([
                'hours_schedule_id' => $schedule->id,
                'flow_id' => $flow->id,
                'after_hours' => 'voicemail',
            ])->save();

            return [$connection, $number, $authToken, $identity];
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CA22222222222222222222222222222222',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => 'client:deskuser',
            'To' => '+15559876543',
            'CallerId' => '+12035551008',
            'Direction' => 'outbound-api',
            'CallStatus' => 'ringing',
        ];

        $xml = $this->signedPost($url, $params, $authToken)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Press 1 for the desk.', $xml);
        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringNotContainsString('This line is closed.', $xml);
        $this->assertStringNotContainsString('Please leave a message after the tone.', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('<Number>+15559876543</Number>', $xml);

        $call = $this->inTenant($t['org'], fn () => PhoneCall::query()->first());
        $this->assertNotNull($call);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame('+12035551008', $call->from_e164);
        $this->assertSame('+15559876543', $call->to_e164);
        $this->assertSame('ringing', $call->status);
    }

    public function test_calls_list_is_limited_to_granted_numbers(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk records', ['phone.view']);

        [$visible, $hidden] = $this->inTenant($t['org'], function () use ($t, $viewer) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'records-auth-token',
            ]);

            $granted = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551901',
                'label' => 'Granted',
            ]);
            $other = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551902',
                'label' => 'Hidden',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $granted->id,
                'role_id' => $viewer->role_id,
            ]);

            $visible = PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $granted->id,
                'twilio_sid' => 'CA33333333333333333333333333333333',
                'direction' => 'inbound',
                'from_e164' => '+15550006666',
                'to_e164' => '+12035551901',
                'status' => 'completed',
                'duration_seconds' => 12,
            ]);
            $hidden = PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $other->id,
                'twilio_sid' => 'CA44444444444444444444444444444444',
                'direction' => 'inbound',
                'from_e164' => '+15550007777',
                'to_e164' => '+12035551902',
                'status' => 'completed',
            ]);

            return [$visible, $hidden];
        });

        $rows = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/calls')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($visible->id, $rows[0]['id']);
        $this->assertSame('+15550006666', $rows[0]['from_e164']);
        $this->assertSame('+12035551901', $rows[0]['to_e164']);
        $this->assertNotContains($hidden->id, array_column($rows, 'id'));
        $this->assertArrayHasKey('customer_id', $rows[0]);
        $this->assertArrayHasKey('voicemail', $rows[0]);
        $this->assertArrayNotHasKey('credentials', $rows[0]);
        $this->assertArrayNotHasKey('auth_token', $rows[0]);
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber, 2: string, 3: string, 4: list<string>}
     */
    private function openLine(array $tenant, string $e164, int $desks = 1): array
    {
        $authToken = 'voice-auth-token-'.$e164;
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => $authToken,
        ]);

        $ids = [];
        $identities = [];
        for ($i = 0; $i < $desks; $i++) {
            $user = $this->deskMember($tenant, 'Desk '.$e164.' '.$i);
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $user->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);
            $ids[] = $user->id;
            $identities[] = ClientIdentity::of($user);
        }

        $group = PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => $ids,
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'e164' => $e164,
            'ring_group_id' => $group->id,
            'record' => true,
        ]);

        return [$connection, $number, $authToken, $identities[0] ?? '', $identities];
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     */
    private function deskMember(array $tenant, string $name): User
    {
        $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test';

        $role = Role::query()->firstOrCreate(
            ['organization_id' => $tenant['org']->id, 'name' => 'Dial members'],
            ['is_system' => false],
        );

        return User::create([
            'organization_id' => $tenant['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => $email,
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function inboundParams(string $callSid, string $from, string $to): array
    {
        return [
            'CallSid' => $callSid,
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => $from,
            'To' => $to,
            'Direction' => 'inbound',
            'CallStatus' => 'ringing',
        ];
    }

    /** @param  array<string, string>  $params */
    private function signedPost(string $url, array $params, string $authToken): \Illuminate\Testing\TestResponse
    {
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);
    }

    private function voiceUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/voice/'.$organizationId.'/'.$connectionId);
    }

    private function statusUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/status/'.$organizationId.'/'.$connectionId);
    }

    private function dialAction(string $xml): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $dial = $document->getElementsByTagName('Dial')->item(0);
        if (! $dial instanceof DOMElement) {
            return '';
        }

        return html_entity_decode($dial->getAttribute('action'));
    }

    private function clientCount(string $xml): int
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $count = 0;
        foreach ($document->getElementsByTagName('Client') as $client) {
            $count++;
        }

        return $count;
    }
}
