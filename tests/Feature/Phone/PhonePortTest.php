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

    public function test_port_webhook_moves_the_row_to_in_progress(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Port editor', ['phone.view', 'phone.edit']);

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
}
