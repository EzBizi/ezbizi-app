<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneRingGroup;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What the call-flow chart may say is happening right now.
 *
 * ── Why so little is served ──────────────────────────────────────
 *
 * Jose asked to "see Live Calls flowing through the Node". Two of those are
 * answerable and the rest are not, and the shape says so rather than padding
 * it out: a caller listening to a menu, a caller ringing a team and a caller
 * holding in a queue are all `status = ringing` on the row, and nothing
 * records which step a call is at.
 *
 * So: exact counts on the queues, asked of Twilio because that is where the
 * callers actually are, and one number for the line. Nothing invented in
 * between.
 */
class PhoneCallFlowLiveTest extends TestCase
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
     * Queue counts come back keyed by RING GROUP, which is what joins them to
     * a box on the chart.
     *
     * The key is the group id rather than my node id on purpose: the node ids
     * are the renderer's, and a screen re-deriving them is the second
     * derivation this module keeps paying for. `graph()` puts the same id on
     * the node as `ref`.
     */
    public function test_queue_counts_are_keyed_by_ring_group(): void
    {
        [$t, , $number, $desk, $group] = $this->line();

        $this->twilio->queues = [
            'ezbizi-'.$group->id => ['waiting' => 3, 'average_wait' => 42],

            // A park queue on the same account, which is a different feature
            // and must not be reported as people holding for a team.
            'ezbizi-park-01a0-whatever' => ['waiting' => 1, 'average_wait' => 0],

            // Somebody else's queue on a shared account.
            'not-ours' => ['waiting' => 9, 'average_wait' => 9],
        ];

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/call-flow/live?line='.$number->id)
            ->assertOk()
            ->assertJsonPath('data.queues.'.$group->id.'.waiting', 3)
            ->assertJsonPath('data.queues.'.$group->id.'.average_wait_seconds', 42)
            ->assertJsonPath('data.queues_known', true)
            ->assertJsonMissingPath('data.queues.not-ours');
    }

    /**
     * "Nobody is waiting" and "we could not ask" are different facts.
     *
     * The first version of this endpoint returned an empty map when Twilio
     * failed, which a screen renders as a row of confident zeroes — the chart
     * would report every queue empty at the exact moment it had no idea. UI/UX
     * asked for the difference before drawing anything.
     *
     * The flag is the answer rather than the emptiness, because an empty map
     * is also what a genuinely quiet afternoon looks like. Both halves are
     * asserted: it must be FALSE when the ask failed, and the test above pins
     * TRUE when it did not — one without the other passes on a build that
     * hard-codes either.
     */
    public function test_a_twilio_failure_is_not_reported_as_empty_queues(): void
    {
        [, , $number, $desk] = $this->line();

        $this->twilio->failQueueSizesWith = 'Twilio is unreachable';

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/call-flow/live?line='.$number->id)
            ->assertOk()
            ->assertJsonPath('data.queues_known', false)

            // And the rest of the payload still works: the graph does not come
            // from Twilio, so a chart must still draw when the counts cannot.
            ->assertJsonPath('data.on_line', 0);
    }

    /**
     * A call that ended and never had its last callback is not "happening now".
     *
     * `status` is moved by Twilio's callbacks, and a call whose final one never
     * arrives stays `ringing` in this table for ever. Without a ceiling the
     * chart shows a caller who hung up last Tuesday, permanently, and the one
     * number on the screen that is supposed to mean "right now" is the one
     * that is never right.
     */
    public function test_a_stale_ringing_row_is_not_counted_as_live(): void
    {
        [$t, , $number, $desk] = $this->line();

        $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING);

        $old = $this->inboundCall($t, $number, PhoneCall::STATUS_RINGING);
        $this->inTenant($t['org'], fn () => PhoneCall::query()->whereKey($old->id)
            ->update(['created_at' => now()->subHours(3)]));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/call-flow/live?line='.$number->id)
            ->assertOk()
            ->assertJsonPath('data.on_line', 1);
    }

    /**
     * A number that is not this person's to read is refused.
     *
     * ── What this does NOT test, and why the first version was wrong ──
     *
     * I first wrote a sibling line on the same connection with no
     * `phone_number_grants` row and expected a 404. It returned 200, and the
     * code was right: Jose ruled on 2026-09-02 that grants are no longer per
     * number — a role with access to Communication gets the company's lines,
     * full stop, because a tenant heading for a hundred numbers cannot tick a
     * hundred boxes per role. That test would have pinned a rule he had
     * deliberately removed.
     *
     * So this asserts the boundary the ENDPOINT owns: a number id that is not
     * in this person's readable set at all. The scoping rules themselves
     * belong to `PhoneNumberAccess` and are tested there rather than
     * re-litigated here.
     */
    public function test_a_number_that_is_not_readable_is_refused(): void
    {
        [, , , $desk] = $this->line();

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/call-flow/live?line=01a00000-0000-7000-8000-000000000000')
            ->assertStatus(404);
    }

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User, 4: PhoneRingGroup} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view', 'phone.settings.view']);

        [$connection, $number, $group] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$desk->id],
                'member_role_ids' => [],
                'overflow' => 'queue',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'ring_group_id' => $group->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$connection, $number, $group];
        });

        return [$t, $connection, $number, $desk, $group];
    }

    private function inboundCall(array $t, PhoneNumber $number, string $status): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => $status,
        ]));
    }
}
