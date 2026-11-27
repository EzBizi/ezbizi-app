<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Taking a call that is ringing somebody else.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * Call pickup, one of the RingCentral features he named. A call rings a group
 * you are not in — Sales rings, you are in Support, everybody in Sales is
 * out — and today the only thing you can do is watch it reach voicemail.
 *
 * ── The guard that matters ───────────────────────────────────────
 *
 * Only while it is RINGING. An in-progress call has somebody talking on it,
 * and redirecting that is not a pickup: it is taking a customer away from a
 * colleague mid-sentence. From the caller's side the two are
 * indistinguishable, which is why the guard is on the status rather than on
 * whether a button was drawn.
 */
class PhoneCallPickupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private FakeTwilioGateway $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $this->twilio);
    }

    /**
     * A ringing call is offered, and pointed at the picker's own devices.
     *
     * Asserted on the TwiML and the leg it is aimed at. "The gateway was
     * called" would pass on a document that rang nobody and on one aimed at
     * the wrong leg.
     */
    public function test_a_ringing_call_can_be_taken_onto_my_own_devices(): void
    {
        [$t, $connection, $number, $desk] = $this->desk();
        $call = $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING);
        $this->device($connection, $desk, 'her-laptop', $t);

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls/ringing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.from_e164', '+13055550100');

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/'.$call->id.'/pickup')
            ->assertOk()
            ->assertJsonPath('data.ringing', 1);

        [, $sid, $twiml] = $this->redirect();

        $this->assertSame(
            (string) $call->twilio_sid,
            $sid,
            'the redirect was aimed at a leg other than the customer',
        );
        $this->assertStringContainsString('<Dial', $twiml);
        $this->assertStringContainsString('<Client', $twiml);
        $this->assertStringContainsString(
            str_replace('-', '', (string) $desk->id),
            $twiml,
            'the call was pointed at somebody other than the person taking it',
        );
    }

    /**
     * A call somebody is ON is not available to take.
     *
     * The case that can distinguish: the call exists, it is on a line this
     * person may see, and the only thing standing between them and a
     * colleague's live conversation is the status check.
     */
    public function test_a_connected_call_cannot_be_taken(): void
    {
        [$t, $connection, $number, $desk] = $this->desk();
        $call = $this->inboundCall($t, $number, PhoneCall::STATUS_IN_PROGRESS);
        $this->device($connection, $desk, 'her-laptop', $t);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/'.$call->id.'/pickup')
            ->assertStatus(409);

        $this->assertSame(
            [],
            $this->twilio->calls,
            'a live call was redirected, which takes a customer off a colleague',
        );

        // And it is not offered either, so nobody is invited to try.
        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls/ringing')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * With no device signed in, the call is left alone.
     *
     * transfer() renders a document that apologises to the caller and takes a
     * message when handed nothing to ring. That is right for a transfer that
     * fails and wrong here: it would END a call that is still alive and still
     * ringing somebody who might yet answer. So the refusal happens before the
     * redirect, and the call keeps ringing.
     */
    public function test_with_no_device_of_mine_the_call_is_left_ringing(): void
    {
        [$t, , $number, $desk] = $this->desk();
        $call = $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/'.$call->id.'/pickup')
            ->assertStatus(409);

        $this->assertSame(
            [],
            $this->twilio->calls,
            'the caller was sent to voicemail by somebody failing to pick up',
        );

        $this->assertSame(
            PhoneCall::STATUS_RINGING,
            $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($call->id)->value('status')),
        );
    }

    /**
     * A row we never heard the end of is not a call.
     *
     * `status` is moved by Twilio's status webhook, and a call whose final
     * callback never arrives stays `ringing` in this table for ever. Without a
     * ceiling the list offers a Pick up button for a conversation that ended
     * last Tuesday, and pressing it redirects a call sid Twilio has forgotten.
     */
    public function test_a_stale_ringing_row_is_not_offered(): void
    {
        [$t, , $number, $desk] = $this->desk();
        $fresh = $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING);

        $old = $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING, '+13055550199');
        $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($old->id)
            ->update(['created_at' => now()->subHours(3)]));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls/ringing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fresh->id);
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User} */
    private function desk(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $desk];
    }

    private function device(PhoneConnection $connection, $user, string $deviceId, array $t): void
    {
        $this->inTenant($t['org'], fn () => PhoneDeviceRegistration::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'platform' => 'web',
            'last_seen_at' => now(),
        ]));
    }

    private function inboundCall(
        array $t,
        PhoneNumber $number,
        string $status,
        string $from = '+13055550100',
    ): PhoneCall {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CAcustomer00000000000000000000'.substr(md5($from), 0, 1),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => $from,
            'to_e164' => $number->e164,
            'status' => $status,
        ]));
    }

    /** The TwiML the gateway was handed, and the SID it was aimed at. */
    private function redirect(): array
    {
        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'redirectCall') {
                return $entry['args'];
            }
        }

        $this->fail('nothing was sent to Twilio, so no call was picked up');
    }
}
