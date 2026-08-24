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
        $this->assertSame('See you at two.', $response->json('data.body'));
        $this->assertSame($customer->id, $response->json('data.customer_id'));
        $this->assertArrayNotHasKey('credentials', $response->json() ?? []);
        $this->assertArrayNotHasKey('auth_token', $response->json() ?? []);

        $send = collect($gateway->calls)->firstWhere('method', 'sendMessage');
        $this->assertNotNull($send);
        $this->assertSame($number->connection_id, $send['args'][0]);
        $this->assertSame('+12035551004', $send['args'][1]);
        $this->assertSame($to, $send['args'][2]);
        $this->assertSame('See you at two.', $send['args'][3]);

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

    public function test_send_without_a_grant_on_the_line_is_forbidden(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'No grant', ['phone.view']);

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
