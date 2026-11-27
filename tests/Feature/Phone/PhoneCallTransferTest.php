<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Moving a live call to another device.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * A call answered at the desk should follow you to your phone on the way out
 * of the door, the way RingCentral does it. Before this the only way to move
 * one was to hang up on the customer and ring them back.
 *
 * ── What is actually being asserted ──────────────────────────────
 *
 * The TwiML handed to Twilio, and which leg it is aimed at. "The gateway was
 * called" would pass on a document that rang nobody, and on one aimed at the
 * employee's own leg — which would take the phone away from the person holding
 * it and leave the customer bridged to nothing.
 */
class PhoneCallTransferTest extends TestCase
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

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User} */
    private function deskOnACall(): array
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

    private function liveCall(array $t, PhoneNumber $number, $answeredBy): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CAcustomer000000000000000000001',
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => PhoneCall::STATUS_IN_PROGRESS,
            'answered_by' => $answeredBy?->id,
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

        $this->fail('nothing was sent to Twilio, so no call moved anywhere');
    }

    public function test_the_customers_leg_is_the_one_redirected(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'desk-pc', $t);
        $this->device($connection, $desk, 'her-iphone', $t);
        $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertOk();

        [, $sid, $twiml] = $this->redirect();

        $this->assertSame(
            'CAcustomer000000000000000000001',
            $sid,
            'the employee leg was redirected, which drops the phone and leaves '
            .'the customer bridged to nothing',
        );

        $this->assertStringContainsString('<Dial', $twiml);
        $this->assertStringContainsString(
            ClientIdentity::of($desk, 'her-iphone'),
            $twiml,
            'the call was moved without ringing the device it was moved to',
        );
    }

    /**
     * And NOT back to the device that asked.
     *
     * The control for the test above. Ringing the desk it just left would move
     * the call to where it already was, and the transfer would look like it
     * worked while nothing had happened.
     */
    public function test_the_asking_device_is_not_rung(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'desk-pc', $t);
        $this->device($connection, $desk, 'her-iphone', $t);
        $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertOk();

        [, , $twiml] = $this->redirect();

        $this->assertStringNotContainsString(ClientIdentity::of($desk, 'desk-pc'), $twiml);
    }

    /**
     * A transfer nobody answers takes a message rather than dropping them.
     *
     * The phone may be in a pocket. A moved call that rings out must not leave
     * the customer listening to nothing — "we cannot afford to lose any call"
     * is the whole reason the batch is set past the first, so afterDial takes
     * a message instead of ringing round again.
     */
    public function test_an_unanswered_transfer_can_still_reach_voicemail(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'desk-pc', $t);
        $this->device($connection, $desk, 'her-iphone', $t);
        $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertOk();

        [, , $twiml] = $this->redirect();

        $this->assertStringContainsString('action=', $twiml);
        $this->assertStringContainsString('batch=2', $twiml);
    }

    /** Somebody who is not on a call is told so, and nothing is sent. */
    public function test_transferring_without_a_call_is_refused(): void
    {
        [$t, $connection, , $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'her-iphone', $t);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertStatus(409);

        $this->assertSame([], $this->twilio->calls);
    }

    /**
     * A call somebody ELSE is on is not mine to move.
     *
     * Without this the lookup would find the newest live call on the account,
     * and pressing the button while a colleague was talking would take their
     * call away from them.
     */
    public function test_a_colleagues_call_is_not_moved(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $other = $this->userWithRole($t, 'Somebody Else', ['phone.view']);

        $this->device($connection, $desk, 'her-iphone', $t);
        $this->liveCall($t, $number, $other);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertStatus(409);

        $this->assertSame([], $this->twilio->calls);
    }

    /** With nowhere to send it, the call is left exactly where it is. */
    public function test_a_lone_device_is_told_there_is_nowhere_to_send_it(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'desk-pc', $t);
        $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertStatus(409);

        $this->assertSame(
            [],
            $this->twilio->calls,
            'a transfer with no target was sent to Twilio anyway',
        );
    }

    /**
     * A call that ended while somebody reached for the button says so.
     *
     * Twilio refuses to redirect a completed call, and that is a fact about
     * the call rather than a fault — a 500 here would read as EzBizi being
     * broken at the exact moment the customer hung up normally.
     */
    public function test_a_call_that_has_already_ended_is_reported_as_that(): void
    {
        [$t, $connection, $number, $desk] = $this->deskOnACall();
        $this->device($connection, $desk, 'desk-pc', $t);
        $this->device($connection, $desk, 'her-iphone', $t);
        $this->liveCall($t, $number, $desk);

        $this->twilio->failRedirectWith = 'Call is not in-progress';

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/transfer', ['from_device_id' => 'desk-pc'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That call has already ended.');
    }
}
