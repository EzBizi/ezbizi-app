<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Dealing with a call on one phone stops your others ringing.
 *
 * ── What Jose reported ───────────────────────────────────────────
 *
 * 2026-09-17: *"all 3 ring, i decline on desktop, it should update that it was
 * declined on the others and stop ringing"*.
 *
 * ── Why Twilio does not do it ────────────────────────────────────
 *
 * A `<Dial>` makes one child leg per `<Client>`, and one person with three
 * devices is three legs. Answering ends the siblings; declining does not, and
 * must not — a leg one person refuses has to leave the call ringing for the
 * colleagues it is also offered to. Twilio cannot tell those apart because it
 * does not know two identities are one human.
 */
class PhoneCallSyncTest extends TestCase
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
     * My other phones stop; the device I acted on is left alone.
     */
    public function test_my_other_devices_are_hung_up_and_this_one_is_not(): void
    {
        [$t, $connection, $number, $me] = $this->line();

        $call = $this->ringing($t, $number);

        $this->twilio->legs[(string) $call->twilio_sid] = [
            ['sid' => 'CAdesk', 'to' => 'client:'.ClientIdentity::of($me, 'desk'), 'status' => 'ringing'],
            ['sid' => 'CAphone', 'to' => 'client:'.ClientIdentity::of($me, 'phone'), 'status' => 'ringing'],
            ['sid' => 'CAtablet', 'to' => 'client:'.ClientIdentity::of($me, 'tablet'), 'status' => 'ringing'],
        ];

        $this->signedInAs($me)
            ->postJson('/api/v1/phone/calls/silence', ['from_device_id' => 'desk'])
            ->assertOk()
            ->assertJsonPath('data.ended', 2);

        $this->assertSame(
            ['CAphone', 'CAtablet'],
            $this->ended(),
            'the wrong legs were hung up',
        );
    }

    /**
     * A COLLEAGUE'S PHONE IS NOT MINE TO HANG UP.
     *
     * The clause the whole endpoint turns on, and the one that makes this
     * feature safe rather than destructive. A ring group offers one call to
     * several people; ending a colleague's leg because I declined would take
     * the call away from the person who was about to answer it — a worse bug
     * than the one being fixed, and silent.
     *
     * Asserted beside my own device so the test can distinguish: a build that
     * ended nothing at all would pass the negative half on its own.
     */
    public function test_a_colleagues_ringing_phone_is_left_alone(): void
    {
        [$t, $connection, $number, $me] = $this->line();
        $mate = $this->userWithRole($t, 'Bar Ohayon', ['phone.view']);

        $call = $this->ringing($t, $number);

        $this->twilio->legs[(string) $call->twilio_sid] = [
            ['sid' => 'CAmine', 'to' => 'client:'.ClientIdentity::of($me, 'phone'), 'status' => 'ringing'],

            // THEIR DEVICE ID MUST DIFFER FROM MINE, and the first version of
            // this test got that wrong. With the colleague on 'desk' too, the
            // device guard spared their leg and the ownership guard was never
            // consulted — so deleting the ownership guard left this test
            // GREEN, which the sabotage run proved. An assertion true for two
            // reasons cannot say which one held.
            ['sid' => 'CAtheirs', 'to' => 'client:'.ClientIdentity::of($mate, 'laptop'), 'status' => 'ringing'],
        ];

        $this->signedInAs($me)
            ->postJson('/api/v1/phone/calls/silence', ['from_device_id' => 'desk'])
            ->assertOk()
            ->assertJsonPath('data.ended', 1);

        $this->assertSame(['CAmine'], $this->ended());
    }

    /**
     * A leg that has already stopped is not asked to stop again.
     *
     * Twilio refuses an update to a completed call, so a build without this
     * would throw on the commonest shape of all: the device that declined,
     * whose leg is already gone by the time it posts.
     */
    public function test_a_leg_that_already_ended_is_not_touched(): void
    {
        [$t, $connection, $number, $me] = $this->line();

        $call = $this->ringing($t, $number);

        $this->twilio->legs[(string) $call->twilio_sid] = [
            ['sid' => 'CAgone', 'to' => 'client:'.ClientIdentity::of($me, 'phone'), 'status' => 'completed'],
            ['sid' => 'CAlive', 'to' => 'client:'.ClientIdentity::of($me, 'tablet'), 'status' => 'ringing'],
        ];

        $this->signedInAs($me)
            ->postJson('/api/v1/phone/calls/silence', ['from_device_id' => 'desk'])
            ->assertOk()
            ->assertJsonPath('data.ended', 1);

        $this->assertSame(['CAlive'], $this->ended());
    }

    /**
     * A call that stopped ringing long ago is not hunted for legs.
     *
     * `status` is moved by Twilio's callbacks and a call whose last one never
     * arrives stays `ringing` in this table for ever. Without a ceiling, every
     * decline would ask Twilio about calls that ended days ago — and could end
     * a leg of one, which by then belongs to a different conversation.
     */
    public function test_a_stale_ringing_row_is_left_alone(): void
    {
        [$t, $connection, $number, $me] = $this->line();

        $call = $this->ringing($t, $number);
        $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($call->id)
            ->update(['created_at' => now()->subHours(3)]));

        $this->twilio->legs[(string) $call->twilio_sid] = [
            ['sid' => 'CAold', 'to' => 'client:'.ClientIdentity::of($me, 'phone'), 'status' => 'ringing'],
        ];

        $this->signedInAs($me)
            ->postJson('/api/v1/phone/calls/silence', ['from_device_id' => 'desk'])
            ->assertOk()
            ->assertJsonPath('data.ended', 0);

        $this->assertSame([], $this->ended());
    }

    /**
     * Without a device id there is no leg to spare, so the request is refused.
     *
     * The alternative is ending every leg of mine including the one that just
     * answered, which hangs up on a customer mid-sentence. `transfer()` treats
     * a missing device id as harmless because there it only means ringing
     * yourself as well; here the same omission is destructive, so the two
     * endpoints validate it differently on purpose.
     */
    public function test_the_asking_device_must_be_named(): void
    {
        [, , , $me] = $this->line();

        $this->signedInAs($me)
            ->postJson('/api/v1/phone/calls/silence', [])
            ->assertStatus(422);
    }

    /** @return list<string> */
    private function ended(): array
    {
        $out = [];

        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'endCall') {
                $out[] = (string) $entry['args'][1];
            }
        }

        sort($out);

        return $out;
    }

    private function ringing(array $t, PhoneNumber $number): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CAparent'.bin2hex(random_bytes(8)),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => PhoneCall::STATUS_RINGING,
        ]));
    }

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $me = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $me) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach (['desk', 'phone', 'tablet'] as $device) {
                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $me->id,
                    'device_id' => $device,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $me->role_id,
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $me];
    }
}
