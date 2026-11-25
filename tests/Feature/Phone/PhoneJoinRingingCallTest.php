<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Coming back while a call is still ringing puts your phone into it.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"A call started ringing, bar was available i was unavailable...
 * i put myself available, and said call should start ringing on me as well if
 * it's still going on."*
 *
 * ── Why a redirect, and what it costs ───────────────────────────
 *
 * A `<Dial>` cannot gain a `<Client>` once it is ringing, so the only way in
 * is to replace the attempt. Every phone already ringing therefore stops and
 * rings again. The customer hears continuous ringback, so the person who
 * notices is a colleague — and that is the trade this makes deliberately.
 */
class PhoneJoinRingingCallTest extends TestCase
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
     * Saying "I am back" redirects the ringing call to include me.
     */
    public function test_becoming_available_pulls_a_ringing_call_onto_my_phone(): void
    {
        [$t, $connection, $number, $me, $mate, $group] = $this->team();

        $call = $this->ringing($t, $number);
        $this->onlyMateIsRinging($call, $mate);

        $this->becomeAvailable($t, $connection, $me);

        $twiml = $this->redirected();

        $this->assertNotNull($twiml, 'the call was not pulled onto the returning phone');
        $this->assertStringContainsString(
            ClientIdentity::of($me, 'desk'),
            $twiml,
            'the redirect did not name the device of the person who came back',
        );

        // And the colleague stays in it. A redirect that rang only the
        // returning person would take the call off the phone that was already
        // ringing, which is worse than not pulling them in at all.
        $this->assertStringContainsString(ClientIdentity::of($mate, 'mate-desk'), $twiml);
    }

    /**
     * NOBODY MAY HAVE ANSWERED — the case that must never happen.
     *
     * `phone_calls.status` is moved by Twilio's callbacks and lags behind the
     * call. Redirecting a call that has just been answered tears down the
     * answered leg: it hangs up on a customer mid-sentence, which is strictly
     * worse than the problem this job solves.
     *
     * So the legs are read from Twilio and an answered one stops everything.
     * Asserted beside the passing case above, because a build that redirected
     * nothing at all would pass this half on its own.
     */
    public function test_a_call_somebody_has_answered_is_never_redirected(): void
    {
        [$t, $connection, $number, $me, $mate] = $this->team();

        $call = $this->ringing($t, $number);

        $this->twilio->legs[(string) $call->twilio_sid] = [
            [
                'sid' => 'CAmate',
                'to' => 'client:'.ClientIdentity::of($mate, 'mate-desk'),

                // Answered a half-second ago. The row still says ringing.
                'status' => 'in-progress',
            ],
        ];

        $this->becomeAvailable($t, $connection, $me);

        $this->assertNull(
            $this->redirected(),
            'a live conversation was redirected, which hangs up on the customer',
        );
    }

    /**
     * A phone of mine already in the attempt means there is nothing to add.
     *
     * Redirecting anyway would restart every colleague's ring to achieve
     * exactly nothing — and this is the common shape, because the desk writes
     * presence on every start-up.
     */
    public function test_a_call_already_ringing_me_is_left_alone(): void
    {
        [$t, $connection, $number, $me, $mate] = $this->team();

        $call = $this->ringing($t, $number);

        $this->twilio->legs[(string) $call->twilio_sid] = [
            ['sid' => 'CAmate', 'to' => 'client:'.ClientIdentity::of($mate, 'mate-desk'), 'status' => 'ringing'],
            ['sid' => 'CAmine', 'to' => 'client:'.ClientIdentity::of($me, 'desk'), 'status' => 'ringing'],
        ];

        $this->becomeAvailable($t, $connection, $me);

        $this->assertNull($this->redirected());
    }

    /**
     * A longest-idle group is left to its own batches.
     *
     * Not an omission. Batched groups re-derive each batch from the dial plan
     * when the previous one ends, so somebody who becomes available during
     * batch one is simply in batch two — with nobody's ring interrupted. And
     * the remaining batches are an ORDER somebody chose; a redirect rings
     * everybody at once and throws that away.
     */
    public function test_a_longest_idle_group_is_not_redirected(): void
    {
        [$t, $connection, $number, $me, $mate, $group] = $this->team();

        $this->inTenant($t['org'], fn () => $group
            ->forceFill(['mode' => PhoneRingGroup::MODE_LONGEST_IDLE])
            ->save());

        $call = $this->ringing($t, $number);
        $this->onlyMateIsRinging($call, $mate);

        $this->becomeAvailable($t, $connection, $me);

        $this->assertNull($this->redirected());
    }

    /**
     * A call that has already left the ring is not dragged back into it.
     *
     * No client legs at all means it has fallen through to voicemail or a
     * forward. Redirecting then would take a caller out of the message they
     * are part-way through leaving.
     */
    public function test_a_call_with_no_ringing_legs_is_left_alone(): void
    {
        [$t, $connection, $number, $me] = $this->team();

        $call = $this->ringing($t, $number);
        $this->twilio->legs[(string) $call->twilio_sid] = [];

        $this->becomeAvailable($t, $connection, $me);

        $this->assertNull($this->redirected());
    }

    /** The TwiML of the one redirect, or null when none was asked for. */
    private function redirected(): ?string
    {
        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'redirectCall') {
                return (string) $entry['args'][2];
            }
        }

        return null;
    }

    private function onlyMateIsRinging(PhoneCall $call, User $mate): void
    {
        $this->twilio->legs[(string) $call->twilio_sid] = [
            [
                'sid' => 'CAmate',
                'to' => 'client:'.ClientIdentity::of($mate, 'mate-desk'),
                'status' => 'ringing',
            ],
        ];
    }

    /**
     * Run the real endpoint the PERSON uses, which runs the job inline.
     *
     * `presence/me` and not `presence`. The first version of this test used
     * the desk's endpoint and the feature never fired — `storedState()`
     * refuses to lift a standing Do Not Disturb there, deliberately, because
     * the desk must not overwrite a decision a person made. So the transition
     * Jose described can only happen through this route, and wiring the job to
     * the other one would have shipped a feature that never ran in the only
     * scenario it was built for.
     *
     * Through the endpoint rather than the job directly, because the dispatch
     * is half the feature: a job nothing reaches is a tested method that
     * cannot be called.
     */
    private function becomeAvailable(array $t, PhoneConnection $connection, User $user): void
    {
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', [
                'state' => PhonePresence::STATE_REGISTERED,
            ])
            ->assertOk();
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

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: User, 4: User, 5: PhoneRingGroup} */
    private function team(): array
    {
        $t = $this->provisionTenant();
        $me = $this->userWithRole($t, 'Jose Demasi', ['phone.view']);
        $mate = $this->userWithRole($t, 'Bar Ohayon', ['phone.view']);

        [$connection, $number, $group] = $this->inTenant($t['org'], function () use ($t, $me, $mate) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ([[$me, 'desk'], [$mate, 'mate-desk']] as [$person, $device]) {
                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'device_id' => $device,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            // I am away; my colleague is taking calls. Exactly the state Jose
            // described, and the reason nothing rings me to begin with.
            PhonePresence::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $me->id,
                'state' => PhonePresence::STATE_DND,
            ]);

            PhonePresence::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $mate->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Existing Client',
                'mode' => PhoneRingGroup::MODE_SIMULTANEOUS,
                'timeout_seconds' => 60,
                'member_user_ids' => [$me->id, $mate->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'ring_group_id' => $group->id,
            ]);

            foreach ([$me, $mate] as $person) {
                PhoneNumberGrant::create([
                    'organization_id' => $t['org']->id,
                    'number_id' => $number->id,
                    'role_id' => $person->role_id,
                ]);
            }

            return [$connection, $number, $group];
        });

        return [$t, $connection, $number, $me, $mate, $group];
    }
}
