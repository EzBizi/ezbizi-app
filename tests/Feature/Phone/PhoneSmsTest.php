<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Inbound SMS storage and the 10DLC outbound gate (Task 12).
 *
 * Public SMS webhook stores the body, matches customers.phone, and raises
 * phone.sms.received. Authenticated send refuses with the fixed sentence until
 * sms_outbound_allowed is true; when allowed it hits the gateway and raises
 * phone.sms.sent.
 */
class PhoneSmsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const REFUSAL = 'This line cannot send texts until messaging is approved.';

    public function test_inbound_sms_is_stored_matched_to_a_customer_and_emitted(): void
    {
        $t = $this->provisionTenant();
        $from = '+15557654321';

        [$connection, $number, $authToken, $customer] = $this->inTenant($t['org'], function () use ($t, $from) {
            [$connection, $number, $authToken] = $this->openLine($t, e164: '+12035551001');

            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-sms-1',
                'name' => 'Texter',
                'phone' => $from,
            ]);

            return [$connection, $number, $authToken, $customer];
        });

        $url = $this->smsUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            messageSid: 'SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            from: $from,
            to: '+12035551001',
            body: 'Need a quote tomorrow',
        );

        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($connection, $number, $customer, $from) {
            $message = PhoneMessage::query()->first();
            $this->assertNotNull($message);
            $this->assertSame('SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $message->twilio_sid);
            $this->assertSame('inbound', $message->direction);
            $this->assertSame($from, $message->from_e164);
            $this->assertSame('+12035551001', $message->to_e164);
            $this->assertSame('Need a quote tomorrow', $message->body);
            $this->assertSame($number->id, $message->number_id);
            $this->assertSame($connection->id, $message->connection_id);
            $this->assertSame($customer->id, $message->customer_id);

            $event = AutomationEvent::query()
                ->where('event_key', 'phone.sms.received')
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($message->id, $event->subject_id);
            $this->assertSame(PhoneMessage::class, $event->subject_type);
            $this->assertSame(1, AutomationEvent::query()->count());
        });
    }

    public function test_a_bad_sms_signature_is_forbidden_and_writes_nothing(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], function () use ($t) {
            [$connection] = $this->openLine($t, e164: '+12035551002');

            return $connection;
        });

        $url = $this->smsUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            messageSid: 'SMbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            from: '+15550001111',
            to: '+12035551002',
            body: 'Nope',
        );

        $this->post($url, $params, [
            'X-Twilio-Signature' => 'not-a-real-signature',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneMessage::query()->count());
            $this->assertSame(0, AutomationEvent::query()->count());
        });
    }

    public function test_send_is_refused_until_the_line_is_approved(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $desk) {
            [, $number] = $this->openLine($t, e164: '+12035551003');
            $number->update(['sms_outbound_allowed' => false]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $number->fresh();
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($desk)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15551112222',
            'body' => 'We can come by at two.',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', self::REFUSAL);

        $this->assertNotContains('sendMessage', array_column($gateway->calls, 'method'));

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneMessage::query()->count());
            $this->assertSame(0, AutomationEvent::query()->count());
        });
    }

    public function test_send_when_allowed_hits_the_gateway_stores_and_emits(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk send', ['phone.view']);
        $to = '+15553334444';

        [$number, $customer] = $this->inTenant($t['org'], function () use ($t, $desk, $to) {
            [, $number] = $this->openLine($t, e164: '+12035551004');
            $number->update(['sms_outbound_allowed' => true]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-sms-2',
                'name' => 'Outbound',
                'phone' => $to,
            ]);

            return [$number->fresh(), $customer];
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($desk)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => $to,
            'body' => 'See you at two.',
        ]);

        $response->assertCreated();
        $this->assertSame('outbound', $response->json('data.direction'));
        $this->assertSame('+12035551004', $response->json('data.from_e164'));
        $this->assertSame($to, $response->json('data.to_e164'));
        // The first text to a number carries the opt-out line (SmsConsent).
        $this->assertSame('See you at two.'."\n\n".\App\Support\Phone\SmsConsent::FOOTER, $response->json('data.body'));
        $this->assertSame($customer->id, $response->json('data.customer_id'));
        $this->assertArrayNotHasKey('credentials', $response->json() ?? []);
        $this->assertArrayNotHasKey('auth_token', $response->json() ?? []);

        $send = collect($gateway->calls)->firstWhere('method', 'sendMessage');
        $this->assertNotNull($send);
        $this->assertSame($number->connection_id, $send['args'][0]);
        $this->assertSame('+12035551004', $send['args'][1]);
        $this->assertSame($to, $send['args'][2]);
        $this->assertSame('See you at two.'."\n\n".\App\Support\Phone\SmsConsent::FOOTER, $send['args'][3]);

        $this->inTenant($t['org'], function () use ($number, $customer, $to, $response) {
            $message = PhoneMessage::query()->find($response->json('data.id'));
            $this->assertNotNull($message);
            $this->assertSame('outbound', $message->direction);
            $this->assertSame($number->id, $message->number_id);
            $this->assertSame($customer->id, $message->customer_id);
            $this->assertSame($to, $message->to_e164);
            $this->assertStringStartsWith('SM', (string) $message->twilio_sid);

            $event = AutomationEvent::query()
                ->where('event_key', 'phone.sms.sent')
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($message->id, $event->subject_id);
            $this->assertSame(PhoneMessage::class, $event->subject_type);
        });
    }

    /** Twilio's region refusal reads as a plain sentence (health sweep 11-09, a Puerto Rico number; Jose: we do not serve Puerto Rico, the permission stays off). */
    public function test_a_region_refusal_reads_as_a_plain_sentence(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk region', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $desk) {
            [, $number] = $this->openLine($t, e164: '+12035551005');
            $number->update(['sms_outbound_allowed' => true]);
            PhoneNumberGrant::create(['organization_id' => $t['org']->id, 'number_id' => $number->id, 'role_id' => $desk->role_id]);

            return $number->fresh();
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failSendMessageWith = "[HTTP 400] Unable to create record: Permission to send an SMS has not been enabled for the region indicated by the 'To' number: +17873700000";

        $this->signedInAs($desk)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+17873700000',
            'body' => 'Hi',
        ])->assertStatus(502)->assertJsonFragment(['message' => "Texts to that number's country are switched off on this phone account, so nothing was sent."]);
    }

    /**
     * Jose, 2026-10-09: a thread with nobody filed on it read as a bare
     * number. The number is named by a customer with that phone first, then a
     * colleague, matched on digits because phones are stored as typed.
     */
    public function test_messages_name_the_other_number_by_customer_then_colleague(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk names', ['phone.view']);

        $this->inTenant($t['org'], function () use ($t, $desk) {
            [, $line] = $this->openLine($t, e164: '+12035551021');
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $line->id,
                'role_id' => $desk->role_id,
            ]);

            $desk->update(['name' => 'Chaim Perez', 'phone' => '(308) 708-9100']);
            Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-sms-peer',
                'name' => 'Phone-Only Customer',
                'phone' => '+15557650000',
            ]);

            foreach (['+13087089100', '+15557650000', '+15550009999'] as $i => $peer) {
                PhoneMessage::query()->create([
                    'connection_id' => $line->connection_id,
                    'number_id' => $line->id,
                    'twilio_sid' => 'SM'.str_repeat((string) $i, 32),
                    'direction' => PhoneMessage::DIRECTION_OUTBOUND,
                    'from_e164' => '+12035551021',
                    'to_e164' => $peer,
                    'body' => 'Thanks',
                ]);
            }
        });

        $names = collect($this->signedInAs($desk)->getJson('/api/v1/phone/messages')
            ->assertOk()
            ->json('data'))
            ->pluck('peer_name', 'to_e164');

        $this->assertSame('Chaim Perez', $names['+13087089100']);
        $this->assertSame('Phone-Only Customer', $names['+15557650000']);
        $this->assertNull($names['+15550009999']);
    }

    public function test_messages_list_covers_lines_in_reach_and_filters_by_peer(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk list', ['phone.view']);

        [$visible, $hidden, $kept] = $this->inTenant($t['org'], function () use ($t, $desk) {
            [$connection, $granted] = $this->openLine($t, e164: '+12035551011');
            $other = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551012',
                'label' => 'Other',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $granted->id,
                'role_id' => $desk->role_id,
            ]);

            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-sms-list',
                'name' => 'Thursday Customer',
                'phone' => '+15557654321',
            ]);

            $kept = PhoneMessage::query()->create([
                'connection_id' => $granted->connection_id,
                'number_id' => $granted->id,
                'customer_id' => $customer->id,
                'twilio_sid' => 'SMcccccccccccccccccccccccccccccccc',
                'direction' => PhoneMessage::DIRECTION_INBOUND,
                'from_e164' => '+15557654321',
                'to_e164' => '+12035551011',
                'body' => 'See you Thursday',
            ]);
            PhoneMessage::query()->create([
                'connection_id' => $granted->connection_id,
                'number_id' => $granted->id,
                'twilio_sid' => 'SMdddddddddddddddddddddddddddddddd',
                'direction' => PhoneMessage::DIRECTION_OUTBOUND,
                'from_e164' => '+12035551011',
                'to_e164' => '+15550001111',
                'body' => 'Other thread',
            ]);
            $hidden = PhoneMessage::query()->create([
                'connection_id' => $other->connection_id,
                'number_id' => $other->id,
                'twilio_sid' => 'SMeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                'direction' => PhoneMessage::DIRECTION_INBOUND,
                'from_e164' => '+15559998888',
                'to_e164' => '+12035551012',
                'body' => 'Hidden line',
            ]);

            return [$granted, $hidden, $kept];
        });

        $all = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/messages')
            ->assertOk()
            ->json('data');

        // Three, not two. The third row is the one this list used to hide for
        // want of a grant on its line; since 2 Sep the tab plus reach is the
        // whole rule, and all three lines belong to this company.
        $this->assertCount(3, $all);
        $this->assertContains($hidden->id, array_column($all, 'id'));
        $this->assertSame('See you Thursday', collect($all)->firstWhere('id', $kept->id)['body']);
        $this->assertArrayHasKey('customer_id', $all[0]);

        // Named, so the Messages table can say who rather than a number; a
        // message with no customer says so with null, not a guess.
        $this->assertSame('Thursday Customer', collect($all)->firstWhere('id', $kept->id)['customer_name']);
        $this->assertNull(collect($all)->firstWhere('id', $hidden->id)['customer_name']);
        $this->assertArrayNotHasKey('credentials', $all[0]);

        $thread = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/messages?'.http_build_query([
                'number_id' => $visible->id,
                'peer' => '+15557654321',
            ]))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $thread);
        $this->assertSame($kept->id, $thread[0]['id']);
        $this->assertSame('See you Thursday', $thread[0]['body']);
    }

    public function test_messages_list_for_an_ungranted_number_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        // Out of reach, not merely ungranted.
        //
        // Grants stopped being per number on 2 Sep, so "no grant" no longer
        // refuses anything. The refusal this test exists for is still real —
        // it just belongs to §4 reach now, so the viewer is pinned to one
        // franchise and the line is company-scoped.
        $viewer = $this->userWithRole($t, 'No grant list', ['phone.view'], onlyAt: $t['franchise']);

        $number = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035551013');

            return $number;
        });

        $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/messages?number_id='.$number->id)
            ->assertForbidden();
    }

    public function test_send_without_a_grant_on_the_line_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        // Out of reach, not merely ungranted.
        //
        // Grants stopped being per number on 2 Sep, so "no grant" no longer
        // refuses anything. The refusal this test exists for is still real —
        // it just belongs to §4 reach now, so the viewer is pinned to one
        // franchise and the line is company-scoped.
        $viewer = $this->userWithRole($t, 'No grant', ['phone.view'], onlyAt: $t['franchise']);

        $number = $this->inTenant($t['org'], function () use ($t) {
            [, $number] = $this->openLine($t, e164: '+12035551005');
            $number->update(['sms_outbound_allowed' => true]);

            return $number->fresh();
        });

        $this->signedInAs($viewer)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15550009999',
            'body' => 'Should not leave.',
        ])->assertForbidden();

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneMessage::query()->count());
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber, 2: string}
     */
    /**
     * Jose, 2026-10-08: A-1's approved toll-free stays on the platform parent
     * (a toll-free verification does not survive a move) but texts for A-1.
     * Twilio only sends a `From` as the account holding it.
     */
    public function test_a_number_held_on_another_account_sends_as_that_account(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk held', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $desk) {
            [, $number] = $this->openLine($t, e164: '+18555550100');
            $number->update(['sms_outbound_allowed' => true, 'account_sid' => 'ACpppppppppppppppppppppppppppppppp']);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $number->fresh();
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($desk)->postJson('/api/v1/phone/messages', [
            'number_id' => $number->id,
            'to' => '+15553334499',
            'body' => 'Thanks for having us.',
        ])->assertCreated();

        $send = collect($gateway->calls)->firstWhere('method', 'sendMessage');
        $this->assertSame('ACpppppppppppppppppppppppppppppppp', $send['args'][4] ?? null);
    }

    /**
     * Replies to a platform-held number are signed with the PARENT's token.
     * They land on the connection only for its own platform-held numbers; the
     * same signature naming any other number of the connection is refused.
     */
    public function test_a_reply_to_a_platform_held_number_is_accepted_and_no_other_number(): void
    {
        config([
            'services.twilio.platform_account_sid' => 'ACpppppppppppppppppppppppppppppppp',
            'services.twilio.platform_auth_token' => 'platform-token',
        ]);
        $t = $this->provisionTenant();

        $connection = $this->inTenant($t['org'], function () use ($t) {
            [$connection, $held] = $this->openLine($t, e164: '+18555550101');
            $held->update(['account_sid' => 'ACpppppppppppppppppppppppppppppppp']);
            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551099',
            ]);

            return $connection;
        });

        $url = $this->smsUrl($t['org']->id, $connection->id);
        $reply = ['AccountSid' => 'ACpppppppppppppppppppppppppppppppp'] + $this->inboundParams(
            messageSid: 'SMcccccccccccccccccccccccccccccccc',
            from: '+15557654300',
            to: '+18555550101',
            body: 'Thank you!',
        );
        $this->signedPost($url, $reply, 'platform-token')->assertOk();

        $other = ['AccountSid' => 'ACpppppppppppppppppppppppppppppppp', 'To' => '+12035551099', 'MessageSid' => 'SMdddddddddddddddddddddddddddddddd'] + $reply;
        $this->signedPost($url, $other, 'platform-token')->assertForbidden();

        $this->inTenant($t['org'], fn () => $this->assertSame(
            ['SMcccccccccccccccccccccccccccccccc'],
            PhoneMessage::query()->pluck('twilio_sid')->all(),
        ));
    }

    private function openLine(array $tenant, string $e164): array
    {
        $authToken = 'sms-auth-token-'.$e164;
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => $authToken,
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'e164' => $e164,
            'label' => 'SMS '.$e164,
            'sms_outbound_allowed' => false,
        ]);

        return [$connection, $number, $authToken];
    }

    /** @return array<string, string> */
    private function inboundParams(string $messageSid, string $from, string $to, string $body): array
    {
        return [
            'MessageSid' => $messageSid,
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => $from,
            'To' => $to,
            'Body' => $body,
            'SmsStatus' => 'received',
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

    private function smsUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/sms/'.$organizationId.'/'.$connectionId);
    }
}
