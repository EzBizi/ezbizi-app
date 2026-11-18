<?php

namespace Tests\Feature\Phone;

use App\Jobs\PlaceQueueCallback;
use App\Models\PhoneCallback;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Keeping the promise, which is the half that makes the offer honest.
 *
 * A caller pressed 1 and was told, in so many words, that we would ring them
 * back. `PhoneQueueCallbackTest` is about whether that happens — the offer and
 * the recording are covered beside the queue itself.
 *
 * ── What is asserted ─────────────────────────────────────────────
 *
 * The call that goes out, and which end of it rings first. "A call was placed"
 * would pass on one that rings the customer and then has nothing to say to
 * them, which is the design this deliberately does not have.
 */
class PhoneQueueCallbackTest extends TestCase
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
     * Going available places the oldest promise, ringing US first.
     */
    public function test_becoming_available_rings_the_employee_then_the_customer(): void
    {
        [$t, $connection, $number, $desk] = $this->deskWithACallbackOwed();

        (new PlaceQueueCallback(
            (string) $t['org']->id,
            (string) $connection->id,
            (string) $desk->id,
        ))->handle(app(\App\Support\Tenancy\TenantContext::class), $this->twilio);

        $created = $this->created();

        // Ringing the EMPLOYEE: `to` is a client, not the customer. The other
        // order rings somebody who asked for a call and then leaves them
        // listening to a dial tone at our end.
        $this->assertStringStartsWith('client:', $created[2]);
        $this->assertSame($number->e164, $created[1], 'the callback went out from the wrong line');

        // And the customer is dialled from inside that call.
        $this->assertStringContainsString('<Dial', $created[3]);
        $this->assertStringContainsString('+13055550100', $created[3]);
        $this->assertStringContainsString('Returning a call', $created[3]);

        $this->inTenant($t['org'], function () {
            $row = PhoneCallback::query()->firstOrFail();

            $this->assertNotNull($row->placed_at, 'the promise is still outstanding');
            $this->assertNotNull($row->taken_by, 'nobody is recorded as having taken it');
        });
    }

    /**
     * A promise already kept is not kept twice.
     *
     * The case that can distinguish: a second desk becoming free a moment
     * later finds the row and must leave it alone. Without the outstanding
     * filter the customer is rung again by the same company for the same
     * reason, which reads worse than having waited.
     */
    public function test_a_placed_callback_is_not_placed_again(): void
    {
        [$t, $connection, , $desk] = $this->deskWithACallbackOwed();

        $this->inTenant($t['org'], fn () => PhoneCallback::query()
            ->update(['placed_at' => now(), 'taken_by' => $desk->id]));

        (new PlaceQueueCallback(
            (string) $t['org']->id,
            (string) $connection->id,
            (string) $desk->id,
        ))->handle(app(\App\Support\Tenancy\TenantContext::class), $this->twilio);

        $this->assertSame(
            [],
            $this->twilio->calls,
            'a callback that had already been placed was placed a second time',
        );
    }

    /**
     * Going available queues the attempt rather than doing it in the request.
     *
     * Asserted on the dispatch rather than on the call, because the point is
     * that the button returns at the speed of a button. Running it inline
     * would put a Twilio round trip — and its failures — in the path of
     * somebody saying "I am back".
     */
    public function test_going_available_queues_the_attempt(): void
    {
        Queue::fake();
        // On the realtime queue, so a backfill never holds a waiting caller (11-18).
        config(['queue.automations_realtime' => 'realtime']);

        [$t, $connection, , $desk] = $this->deskWithACallbackOwed();

        $this->signedInAs($desk)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $connection->id,
                'state' => 'registered',
            ])
            ->assertOk();

        Queue::assertPushedOn('realtime', PlaceQueueCallback::class);
    }

    /**
     * And going AWAY does not.
     *
     * The control. Without it this passes on a build that fires the job on
     * every presence write, including somebody switching themselves off —
     * which would ring a customer from the desk of a person who has just said
     * they are unavailable.
     */
    public function test_going_away_does_not_queue_an_attempt(): void
    {
        Queue::fake();

        [$t, $connection, , $desk] = $this->deskWithACallbackOwed();

        $this->signedInAs($desk)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk();

        Queue::assertNotPushed(PlaceQueueCallback::class);
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User} */
    private function deskWithACallbackOwed(): array
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
                'e164' => '+13057249401',
            ]);

            PhoneDeviceRegistration::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'device_id' => 'her-laptop',
                'platform' => 'web',
                'last_seen_at' => now(),
            ]);

            PhonePresence::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            PhoneCallback::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'to_e164' => '+13055550100',
                'position' => 2,
                'requested_at' => now()->subMinutes(5),
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $desk];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} connection, from, to, twiml */
    private function created(): array
    {
        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'createCall') {
                return $entry['args'];
            }
        }

        $this->fail('no call was placed, so the promise was not kept');
    }
}
