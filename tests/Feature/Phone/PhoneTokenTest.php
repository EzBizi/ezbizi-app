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

    /**
     * The phone says what it is, and the endpoint passes it on.
     *
     * The gateway can mint a push-bearing token all day; if nothing tells it a
     * handset is asking, every token is a browser token and no phone rings.
     * PhonePushTokenTest proves the grant, this proves it is reached.
     */
    public function test_a_handset_says_which_platform_it_is(): void
    {
        [$desk, $connection] = $this->deskWithALine('Handset');

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
            'platform' => 'ios',
        ])->assertOk();

        $call = collect($gateway->calls)->firstWhere('method', 'accessToken');
        $this->assertSame('ios', $call['args'][2] ?? null);
    }

    /**
     * A browser sends nothing, and the gateway hears nothing.
     *
     * The control for the test above: without it, an implementation that
     * hardcoded 'ios' would pass that one and attach an APNs credential to
     * every desktop token.
     */
    public function test_a_browser_sends_no_platform_and_the_gateway_hears_none(): void
    {
        [$desk, $connection] = $this->deskWithALine('Browser');

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
        ])->assertOk();

        $call = collect($gateway->calls)->firstWhere('method', 'accessToken');
        $this->assertNull($call['args'][2] ?? null);
    }

    /** A platform nobody has a credential for is a mistake, not a silent null. */
    public function test_an_unknown_platform_is_refused(): void
    {
        [$desk, $connection] = $this->deskWithALine('Unknown');

        $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
            'platform' => 'blackberry',
        ])->assertStatus(422);
    }

    /** @return array{0: \App\Models\User, 1: PhoneConnection} */
    private function deskWithALine(string $label): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, $label, ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t, $desk, $label) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'twiml_app_sid' => 'APcccccccccccccccccccccccccccccccc',
            ]);
            $connection->writeSecrets('SK-secret', 'auth-token');

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+1203555'.substr(str_pad((string) crc32($label), 4, '0'), 0, 4),
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $connection;
        });

        return [$desk, $connection];
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

    /** The desk registers on every account with a line (EverDoors' ring group, Jose 2026-11-11). */
    public function test_token_index_lists_every_account_with_a_line(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk accounts', ['phone.view']);

        [$a, $b, $empty] = $this->inTenant($t['org'], function () use ($t) {
            $made = [];
            $second = \App\Models\Company::create(['organization_id' => $t['org']->id, 'name' => 'Second']);
            $scopes = [[PhoneConnection::SCOPE_ORGANIZATION, $t['org']->id], ['company', $t['company']->id], ['company', $second->id]];
            foreach (['+12035551051', '+12035551052', null] as $i => $e164) {
                $connection = PhoneConnection::factory()->create([
                    'scope_type' => $scopes[$i][0],
                    'scope_id' => $scopes[$i][1],
                    'status' => PhoneConnection::STATUS_CONNECTED,
                ]);
                if ($e164 !== null) {
                    PhoneNumber::factory()->create([
                        'connection_id' => $connection->id,
                        'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                        'scope_id' => $t['org']->id,
                        'e164' => $e164,
                    ]);
                }
                $made[] = $connection;
            }

            return $made;
        });

        $ids = collect($this->signedInAs($desk)->getJson('/api/v1/phone/registrations')->assertOk()->json('data'))->pluck('connection_id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        $this->assertNotContains($empty->id, $ids);

        // A ring-group member with no line there still takes its calls (Jose on Owners).
        $this->inTenant($t['org'], fn () => \App\Models\PhoneRingGroup::query()->create([
            'connection_id' => $empty->id, 'name' => 'Owners', 'mode' => 'simultaneous',
            'timeout_seconds' => 30, 'member_user_ids' => [$desk->id], 'member_role_ids' => [], 'overflow' => 'voicemail',
        ]));
        $ids = collect($this->signedInAs($desk)->getJson('/api/v1/phone/registrations')->assertOk()->json('data'))->pluck('connection_id')->all();
        $this->assertContains($empty->id, $ids);
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', ['connection_id' => $empty->id, 'state' => 'registered'])->assertOk();
    }

    public function test_presence_list_returns_teammates_on_the_connection(): void
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Desk list', ['phone.view']);
        $other = $this->userWithRole($t, 'Other desk', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t, $desk, $other) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551041',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $other->role_id,
            ]);

            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $other->id,
                'state' => PhonePresence::STATE_ON_CALL,
            ]);

            // A removed person's row stays behind; Team must not list it (Jose 11-09).
            $gone = $this->userWithRole($t, 'Removed', ['phone.view']);
            $gone->forceFill(['is_active' => false, 'name' => 'Removed account'])->save();
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $gone->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            return $connection;
        });

        $rows = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/presence?connection_id='.$connection->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $byUser = collect($rows)->keyBy('user_id');
        $this->assertSame($desk->name, $byUser[$desk->id]['name']);
        $this->assertSame('registered', $byUser[$desk->id]['state']);
        $this->assertSame($other->name, $byUser[$other->id]['name']);
        $this->assertSame('on_call', $byUser[$other->id]['state']);
        $this->assertArrayHasKey('updated_at', $byUser[$desk->id]);
        $this->assertArrayNotHasKey('credentials', $rows[0]);
    }

    public function test_presence_list_without_a_line_is_refused(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'No line list', ['phone.view']);

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            return $connection;
        });

        $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/presence?connection_id='.$connection->id)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You have not been given a line on this phone account.',
            );
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
