<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Voice SDK access tokens and desk presence (Task 9).
 *
 * Token needs phone.view and at least one granted number on that connection.
 * Presence is one current row per (connection, user), replaced in place.
 */
class PhoneTokenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_granted_caller_receives_a_voice_token_for_that_connection(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'twiml_app_sid' => 'APcccccccccccccccccccccccccccccccc',
            ]);
            $connection->writeSecrets('SK-secret-for-token', 'auth-token-for-sig');

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551001',
                'label' => 'Main',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $connection;
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $response = $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
        ]);

        $response->assertOk();
        $identity = ClientIdentity::of($desk);
        $this->assertSame($identity, $response->json('identity'));
        $this->assertSame('APcccccccccccccccccccccccccccccccc', $response->json('twiml_app_sid'));
        $this->assertSame(
            'fake-access-token.'.$identity.'.'.$connection->id,
            $response->json('token'),
        );
        $this->assertArrayNotHasKey('credentials', $response->json() ?? []);
        $this->assertArrayNotHasKey('api_secret', $response->json() ?? []);
        $this->assertArrayNotHasKey('auth_token', $response->json() ?? []);

        $methods = array_column($gateway->calls, 'method');
        $this->assertContains('accessToken', $methods);
        $tokenCall = collect($gateway->calls)->firstWhere('method', 'accessToken');
        $this->assertSame($connection->id, $tokenCall['args'][0]);
        $this->assertSame($identity, $tokenCall['args'][1]);
    }

    public function test_without_a_line_on_the_connection_the_token_is_refused(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Viewer', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'twiml_app_sid' => 'APdddddddddddddddddddddddddddddddd',
            ]);
            $connection->writeSecrets('SK-secret', 'auth-token');

            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551009',
            ]);

            return $connection;
        });

        $this->signedInAs($viewer)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You have not been given a line on this phone account.',
            );
    }

    public function test_presence_is_upserted_for_a_granted_caller(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk presence', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'api_key_sid' => 'SKeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                'twiml_app_sid' => 'APffffffffffffffffffffffffffffffff',
            ]);
            $connection->writeSecrets('SK-secret', 'auth-token');

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551021',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $connection;
        });

        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk();

        $row = $this->inTenant($t['org'], fn () => PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $desk->id)
            ->first());

        $this->assertNotNull($row);
        $this->assertSame(PhonePresence::STATE_REGISTERED, $row->state);

        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'dnd',
        ])->assertOk();

        $this->inTenant($t['org'], function () use ($connection, $desk) {
            $this->assertSame(1, PhonePresence::query()
                ->where('connection_id', $connection->id)
                ->where('user_id', $desk->id)
                ->count());
            $this->assertSame(
                PhonePresence::STATE_DND,
                PhonePresence::query()
                    ->where('connection_id', $connection->id)
                    ->where('user_id', $desk->id)
                    ->value('state'),
            );
        });
    }

    public function test_presence_without_a_line_is_refused(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'No line', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551031',
            ]);

            return $connection;
        });

        $this->signedInAs($viewer)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'on_call',
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You have not been given a line on this phone account.',
            );
    }
}
