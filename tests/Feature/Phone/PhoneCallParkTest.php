<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\User;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Setting a live call down so somebody else can pick it up.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * Call park, one of the RingCentral features he asked for by name. Somebody
 * answers, realises the call is for a colleague who is not at their desk, and
 * needs to put the caller somewhere rather than hang up on them or hold the
 * phone until that colleague appears.
 *
 * ── Why there are no park slots ──────────────────────────────────
 *
 * RingCentral has numbered Park Locations because a desk phone has buttons and
 * a button needs a number. Nothing here has buttons, so the queue is named
 * after the CALL and the screen lists the calls themselves — which is the more
 * useful thing to read anyway. You pick up "the call from +1 305…", not "slot
 * 3", and there is no slot to allocate, run out of, or collide on.
 *
 * ── What is actually asserted ────────────────────────────────────
 *
 * The TwiML, which leg it is aimed at, and the state left behind. "The gateway
 * was called" would pass on a document that parked nobody, and on one aimed at
 * the employee's own leg — which would take the phone away from the person
 * holding it and leave the customer bridged to nothing.
 */
class PhoneCallParkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'call-park-auth-token';

    private FakeTwilioGateway $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $this->twilio);
    }

    /**
     * The CUSTOMER's leg is the one that goes on holding.
     *
     * Aimed at their call sid, not the desk's. Redirecting our own leg would
     * take the phone away from the person pressing the button and leave the
     * customer connected to nothing — the same failure transfer() guards, and
     * it is silent: the button appears to work and the caller is simply gone.
     */
    public function test_parking_puts_the_customers_leg_in_its_own_queue(): void
    {
        [$t, , $number, $desk] = $this->desk();
        $call = $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/park')
            ->assertOk()
            ->assertJsonPath('data.call_id', $call->id);

        [, $sid, $twiml] = $this->redirect();

        $this->assertSame(
            'CAcustomer000000000000000000001',
            $sid,
            'the desk parked its own leg, so the customer is bridged to nothing',
        );

        $this->assertStringContainsString('<Enqueue', $twiml);
        $this->assertStringContainsString('ezbizi-park-'.$call->id, $twiml);

        // No waitUrl on purpose: with none Twilio plays its own hold music,
        // and a parked call has no queue position to announce.
        $this->assertStringNotContainsString('waitUrl', $twiml);
    }

    /**
     * The row says parked, and says who did it.
     *
     * `parked_by` is the half somebody actually needs — a board of waiting
     * callers with no names on it tells you a call exists and not who to ask
     * about it.
     */
    public function test_a_parked_call_is_listed_with_who_parked_it(): void
    {
        [$t, , $number, $desk] = $this->desk();
        $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)->postJson('/api/v1/phone/calls/park')->assertOk();

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls/parked')
            ->assertOk()
            ->assertJsonPath('data.0.from_e164', '+13055550100')
            ->assertJsonPath('data.0.parked_by_name', 'Adeline Ruiz')
            ->assertJsonCount(1, 'data');
    }

    /**
     * Picking it up is what un-parks it, and nothing else has to be told.
     *
     * There is deliberately no un-park request. A screen that has to tell the
     * server it picked something up leaves the board wrong whenever it crashes,
     * closes, or loses the network between the two calls — and a parked call
     * nothing will ever clear is a row somebody has to delete by hand.
     *
     * Dialling the queue IS the retrieval, so the voice webhook is the only
     * moment that certainly happened.
     */
    public function test_dialling_the_park_queue_bridges_the_call_and_clears_it(): void
    {
        [$t, $connection, $number, $desk] = $this->desk();
        $call = $this->liveCall($t, $number, $desk);

        $this->signedInAs($desk)->postJson('/api/v1/phone/calls/park')->assertOk();

        $this->assertNotNull(
            $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($call->id)->value('parked_at')),
            'the fixture is not parked, so clearing it proves nothing',
        );

        // The desk dialling the queue, which is an ordinary outbound call with
        // a park: destination rather than a number.
        $xml = $this->inTenant($t['org'], function () use ($number, $call) {
            return (new TwimlRenderer)->inbound($number->fresh(), [
                'outbound' => true,
                'dial_to' => 'park:'.$call->parkQueueName(),
                'caller_id' => $number->e164,
            ]);
        });

        $this->assertStringContainsString('<Queue', $xml);
        $this->assertStringContainsString('ezbizi-park-'.$call->id, $xml);

        // And the webhook, which is what actually clears the stamp.
        $this->postVoice($t['org']->id, $connection->id, [
            'From' => 'client:'.str_replace('-', '', (string) $desk->id),
            'To' => 'park:'.$call->parkQueueName(),
            'Direction' => 'outbound-api',
            'CallSid' => 'CApickup00000000000000000000001',
        ])->assertOk();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($call->id)->value('parked_at')),
            'the call was picked up and the board still says it is waiting',
        );
    }

    /**
     * Parking with nothing to park is refused rather than guessed at.
     *
     * Without the check the lookup finds the newest live call on the account,
     * so pressing the button while a colleague was talking would park THEIR
     * caller — from their point of view the customer simply vanishes.
     */
    public function test_parking_without_a_call_is_refused(): void
    {
        [, , , $desk] = $this->desk();

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/park')
            ->assertStatus(409);

        $this->assertSame([], $this->twilio->calls);
    }

    /**
     * A call somebody else is on is not mine to park.
     *
     * The same guard as above from the other side, and the case that can
     * distinguish: there IS a live call, it is simply not this person's.
     */
    public function test_a_colleagues_call_is_not_parked(): void
    {
        [$t, , $number, $desk] = $this->desk();
        $colleague = $this->userWithRole($t, 'Bruno Vega', ['phone.view']);
        $this->liveCall($t, $number, $colleague);

        $this->signedInAs($desk)
            ->postJson('/api/v1/phone/calls/park')
            ->assertStatus(409);

        $this->assertSame([], $this->twilio->calls);
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: User} */
    private function desk(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => self::AUTH_TOKEN,
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

    /**
     * Twilio signs every webhook and the endpoint checks it, so a test that
     * posts unsigned is testing the 403 rather than the feature.
     *
     * @param  array<string, mixed>  $params
     */
    private function postVoice(string $org, string $connection, array $params): TestResponse
    {
        $url = url("/api/v1/phone/voice/{$org}/{$connection}");
        $signable = array_map(static fn ($value) => (string) $value, $params);

        return $this->post($url, $signable, [
            'X-Twilio-Signature' => (new RequestValidator(self::AUTH_TOKEN))
                ->computeSignature($url, $signable),
        ]);
    }

    /** The TwiML the gateway was handed, and the SID it was aimed at. */
    private function redirect(): array
    {
        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'redirectCall') {
                return $entry['args'];
            }
        }

        $this->fail('nothing was sent to Twilio, so no call was parked');
    }
}
