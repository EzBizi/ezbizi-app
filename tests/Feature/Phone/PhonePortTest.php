<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhonePortRequest;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Port-in request + status webhook (Task 13).
 *
 * POST /api/v1/phone/ports starts a Twilio port via the gateway and stores a
 * phone_port_requests row. The public port webhook moves that row’s status
 * (e.g. to in_progress) without Sanctum — signature is auth.
 */
class PhonePortTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Ports could be started and never read.
     *
     * The row existed, the carrier webhook updated its status for weeks, and
     * no endpoint listed it — so the most anxious thing this module does was
     * also the only one with no way to check on it.
     */
    public function test_ports_can_be_listed_and_say_whether_they_are_settled(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $this->inTenant($t['org'], function () use ($t, $connection) {
            PhonePortRequest::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'e164s' => ['+19544538774'],
                'twilio_sid' => 'PO'.str_repeat('a', 32),
                'status' => 'pending',
            ]);

            PhonePortRequest::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'e164s' => ['+19545550101'],
                'twilio_sid' => 'PO'.str_repeat('b', 32),
                'status' => 'canceled',
            ]);
        });

        $rows = collect(
            $this->signedInAs($editor)->getJson('/api/v1/phone/ports')->assertOk()->json('data')
        )->keyBy('twilio_sid');

        $this->assertCount(2, $rows);

        // `settled` is decided by the server so the web client and the phone
        // app cannot disagree about whether a port is still moving.
        $this->assertFalse($rows['PO'.str_repeat('a', 32)]['settled']);
        $this->assertTrue($rows['PO'.str_repeat('b', 32)]['settled']);
        $this->assertSame(['+19544538774'], $rows['PO'.str_repeat('a', 32)]['e164s']);
    }

    public function test_port_webhook_moves_the_row_to_in_progress(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Port editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            return PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'port-auth-token',
            ]);
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $create = $this->signedInAs($editor)->postJson('/api/v1/phone/ports', [
            'connection_id' => $connection->id,
            'e164s' => ['+12035559876'],
        ]);

        $create->assertCreated();
        $this->assertSame('waiting_for_signature', $create->json('data.status'));
        $this->assertNotSame('', (string) $create->json('data.twilio_sid'));
        $this->assertContains('startPort', array_column($gateway->calls, 'method'));

        $row = $this->inTenant($t['org'], fn () => PhonePortRequest::query()->first());
        $this->assertNotNull($row);
        $this->assertSame($connection->id, $row->connection_id);
        $this->assertSame(['+12035559876'], $row->e164s);
        $this->assertSame('waiting_for_signature', $row->status);

        $url = url('/api/v1/phone/port/'.$t['org']->id.'/'.$connection->id);
        $params = [
            'port_in_request_sid' => $row->twilio_sid,
            'status' => 'in_progress',
            'rejection_reason' => '',
        ];
        $signature = (new RequestValidator('port-auth-token'))->computeSignature($url, $params);

        $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ])->assertOk();

        $this->inTenant($t['org'], function () use ($row) {
            $fresh = PhonePortRequest::query()->find($row->id);
            $this->assertNotNull($fresh);
            $this->assertSame('in_progress', $fresh->status);
        });
    }

    public function test_json_body_sha256_port_webhook_moves_the_row_to_in_progress(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Port json', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            return PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'port-json-auth-token',
            ]);
        });

        $create = $this->signedInAs($editor)->postJson('/api/v1/phone/ports', [
            'connection_id' => $connection->id,
            'e164s' => ['+12035558765'],
        ]);
        $create->assertCreated();

        $row = $this->inTenant($t['org'], fn () => PhonePortRequest::query()->first());
        $this->assertNotNull($row);
        $this->assertSame('waiting_for_signature', $row->status);

        $body = json_encode([
            'port_in_request_sid' => $row->twilio_sid,
            'status' => 'in_progress',
            'rejection_reason' => null,
        ], JSON_THROW_ON_ERROR);
        $hash = RequestValidator::computeBodyHash($body);
        $url = url('/api/v1/phone/port/'.$t['org']->id.'/'.$connection->id).'?bodySHA256='.$hash;
        $signature = (new RequestValidator('port-json-auth-token'))->computeSignature($url, []);

        $this->call(
            'POST',
            $url,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TWILIO_SIGNATURE' => $signature,
            ],
            $body,
        )->assertOk();

        $this->inTenant($t['org'], function () use ($row) {
            $fresh = PhonePortRequest::query()->find($row->id);
            $this->assertNotNull($fresh);
            $this->assertSame('in_progress', $fresh->status);
        });
    }
}
