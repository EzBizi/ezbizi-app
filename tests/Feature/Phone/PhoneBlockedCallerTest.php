<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\PhoneBlockedNumber;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Support\Phone\ClientIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * A caller the company has blocked never becomes a ringing phone.
 *
 * ── Why the check is on the webhook and not in the app ───────────
 *
 * "Blocked" has to mean the handsets stay quiet. A list the Communication
 * screen filters would leave the call ringing every desk in the ring group
 * and then hide it from the history afterwards — the opposite of the point,
 * and the kind of feature that looks like it works because the screen agrees
 * with itself.
 *
 * So every assertion here is about the TwiML Twilio is handed, which is the
 * only thing that decides whether a phone rings.
 */
class PhoneBlockedCallerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SPAMMER = '+13055550911';

    private const CUSTOMER = '+13055550112';

    public function test_a_blocked_caller_is_rejected_and_rings_nobody(): void
    {
        [$t, $connection, $number, $authToken] = $this->line();

        $this->inTenant($t['org'], fn () => PhoneBlockedNumber::factory()->create([
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
            'reason' => 'Robocall, four times this week',
        ]));

        $xml = $this->ring($t, $connection, $authToken, self::SPAMMER, 'CA'.str_repeat('1', 32));

        $this->assertStringContainsString('<Reject', $xml);

        // The whole feature, stated as the thing that must NOT be in the
        // document. A Reject that arrives after a Dial rings everybody first.
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
    }

    /**
     * The control. An ordinary caller on the same line still gets through.
     *
     * Without this the test above passes for a webhook that rejects
     * everything, which is a phone system that takes no calls at all.
     */
    public function test_an_unblocked_caller_on_the_same_line_still_rings(): void
    {
        [$t, $connection, $number, $authToken] = $this->line();

        $this->inTenant($t['org'], fn () => PhoneBlockedNumber::factory()->create([
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
        ]));

        $xml = $this->ring($t, $connection, $authToken, self::CUSTOMER, 'CA'.str_repeat('2', 32));

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Reject', $xml);
    }

    /**
     * One company's block is not another's.
     *
     * The list is keyed on the connection, and a connection is a company. A
     * query that forgot that clause would still pass both tests above, and
     * would quietly stop one customer's calls reaching a different business.
     */
    public function test_a_block_on_another_company_does_not_reach_this_one(): void
    {
        [$t, $connection, $number, $authToken] = $this->line();

        $other = $this->inTenant($t['org'], function () use ($t) {
            // At COMPANY scope: phone_connections is unique on
            // (organization_id, scope_type, scope_id), so a second
            // organization-scoped row cannot exist -- which is itself the
            // reason a connection stands for a company.
            $row = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'auth_token' => 'another-companys-token',
            ]);

            PhoneBlockedNumber::factory()->create([
                'connection_id' => $row->id,
                'e164' => self::SPAMMER,
            ]);

            return $row;
        });

        $this->assertNotSame($connection->id, $other->id);

        $xml = $this->ring($t, $connection, $authToken, self::SPAMMER, 'CA'.str_repeat('3', 32));

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Reject', $xml);
    }

    /**
     * A blocked call is still a call somebody can see.
     *
     * "Why did nobody ring me back?" has a findable answer only if the row is
     * there. A block that also erased the evidence would be indistinguishable
     * from the phone being broken.
     */
    public function test_the_blocked_call_is_recorded_as_blocked(): void
    {
        [$t, $connection, $number, $authToken] = $this->line();

        $this->inTenant($t['org'], fn () => PhoneBlockedNumber::factory()->create([
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
        ]));

        $sid = 'CA'.str_repeat('4', 32);
        $this->ring($t, $connection, $authToken, self::SPAMMER, $sid);

        $call = $this->inTenant(
            $t['org'],
            fn () => PhoneCall::query()->where('twilio_sid', $sid)->first(),
        );

        $this->assertNotNull($call, 'a blocked call left no trace at all');
        $this->assertSame(PhoneCall::STATUS_BLOCKED, $call->status);
        $this->assertSame(self::SPAMMER, $call->from_e164);
    }

    /**
     * Nothing downstream is told a customer is calling.
     *
     * `phone.call.started` is what creates leads and pings people. Raising it
     * for a caller the company just blocked would hand the robocaller the
     * notification it could not get by ringing.
     */
    public function test_a_blocked_call_raises_no_started_event(): void
    {
        [$t, $connection, $number, $authToken] = $this->line();

        $this->inTenant($t['org'], fn () => PhoneBlockedNumber::factory()->create([
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
        ]));

        $this->ring($t, $connection, $authToken, self::SPAMMER, 'CA'.str_repeat('5', 32));

        $blocked = $this->inTenant($t['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'phone.call.started')
            ->count());

        $this->assertSame(0, $blocked);

        // Positive control: the same call from an unblocked number DOES raise
        // it, so the zero above is the block working rather than the event
        // never firing on this path at all.
        $this->ring($t, $connection, $authToken, self::CUSTOMER, 'CA'.str_repeat('6', 32));

        $this->assertSame(1, $this->inTenant($t['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'phone.call.started')
            ->count()));
    }

    /**
     * A withheld number is not a blocked one.
     *
     * Twilio sends an empty `From` for an anonymous caller. An exact match
     * against a blank row would reject every one of them, and this is the one
     * `=== ''` that stands between the two.
     */
    public function test_an_empty_caller_matches_nothing(): void
    {
        [$t, $connection] = $this->line();

        $this->inTenant($t['org'], function () use ($connection) {
            // Nothing writes a blank row today. Forced in on purpose: the
            // guard is worth nothing if the only proof it works is that the
            // bad row cannot exist.
            PhoneBlockedNumber::factory()->create([
                'connection_id' => $connection->id,
                'e164' => '',
            ]);

            $this->assertFalse(PhoneBlockedNumber::blocks($connection->id, ''));

            // And the row really is there, so the false above is the guard.
            $this->assertTrue(PhoneBlockedNumber::query()
                ->where('connection_id', $connection->id)
                ->where('e164', '')
                ->exists());
        });
    }

    // ── The screen that puts a number on the list ────────────────────

    /**
     * Blocking is an edit to how the phone system routes, and gated as one.
     *
     * Deliberately NOT a permission of its own. Somebody trusted to say which
     * line rings whom is trusted to say which caller rings nobody, and a
     * `phone.block` nobody remembers to grant is a screen that looks broken.
     */
    public function test_viewing_is_not_enough_to_block_somebody(): void
    {
        [$t, $connection] = $this->line();
        $viewer = $this->userWithRole($t, 'Reads only', ['phone.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/phone/blocked-numbers', [
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
        ])->assertForbidden();

        $this->assertSame(0, $this->inTenant(
            $t['org'],
            fn () => PhoneBlockedNumber::query()->count(),
        ));

        // Positive control: the same request from somebody who may edit does
        // land, so the refusal above is the permission and not a broken route.
        $editor = $this->userWithRole($t, 'Runs the phones', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/blocked-numbers', [
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
        ])->assertCreated();

        $this->assertSame(1, $this->inTenant(
            $t['org'],
            fn () => PhoneBlockedNumber::query()->count(),
        ));
    }

    /**
     * Pressing Block twice does not blow up, and does not make two rows.
     *
     * The unique index would turn the second press into a 500. Two rows would
     * be worse: unblocking removes one, the caller stays blocked, and the
     * screen says they are not.
     */
    public function test_blocking_the_same_caller_twice_is_one_row(): void
    {
        [$t, $connection] = $this->line();
        $editor = $this->userWithRole($t, 'Twice', ['phone.view', 'phone.edit']);

        foreach (['Robocall', 'Still calling'] as $reason) {
            $this->signedInAs($editor)->postJson('/api/v1/phone/blocked-numbers', [
                'connection_id' => $connection->id,
                'e164' => self::SPAMMER,
                'reason' => $reason,
            ])->assertCreated();
        }

        $rows = $this->inTenant($t['org'], fn () => PhoneBlockedNumber::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('Still calling', $rows->first()->reason);
    }

    /** Something that is not a phone number cannot be blocked. */
    public function test_a_number_that_is_not_a_number_is_refused(): void
    {
        [$t, $connection] = $this->line();
        $editor = $this->userWithRole($t, 'Types badly', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/blocked-numbers', [
            'connection_id' => $connection->id,
            'e164' => '305 555 0911',
        ])->assertStatus(422);
    }

    /**
     * The list, and unblocking from it.
     *
     * A block with no way back is a customer you cannot reach again, and the
     * only sign of it is a call that never comes.
     */
    public function test_a_blocked_caller_can_be_listed_and_unblocked(): void
    {
        [$t, $connection, , $authToken] = $this->line();
        $editor = $this->userWithRole($t, 'Unblocks', ['phone.view', 'phone.edit']);

        $id = $this->signedInAs($editor)->postJson('/api/v1/phone/blocked-numbers', [
            'connection_id' => $connection->id,
            'e164' => self::SPAMMER,
            'reason' => 'Robocall',
        ])->assertCreated()->json('data.id');

        $rows = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/blocked-numbers')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(self::SPAMMER, $rows[0]['e164']);
        $this->assertSame('Robocall', $rows[0]['reason']);
        $this->assertSame($editor->name, $rows[0]['blocked_by_name']);

        // Blocked, so the call is refused...
        $this->assertStringContainsString(
            '<Reject',
            $this->ring($t, $connection, $authToken, self::SPAMMER, 'CA'.str_repeat('7', 32)),
        );

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/blocked-numbers/'.$id)
            ->assertOk();

        // ...and once unblocked the same caller rings again. Asserted on the
        // TwiML rather than on the row count, because the row disappearing is
        // not the thing anybody cares about.
        $this->assertStringContainsString(
            '<Dial',
            $this->ring($t, $connection, $authToken, self::SPAMMER, 'CA'.str_repeat('8', 32)),
        );
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: string} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $authToken = 'blocked-caller-auth-token';

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $authToken) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => $authToken,
            ]);

            $desk = $this->userWithRole($t, 'Blocked caller desk', ['phone.view']);
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$desk->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550104',
                'ring_group_id' => $group->id,
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $authToken];
    }

    private function ring(
        array $t,
        PhoneConnection $connection,
        string $authToken,
        string $from,
        string $sid,
    ): string {
        $url = url('/api/v1/phone/voice/'.$t['org']->id.'/'.$connection->id);

        $params = [
            'CallSid' => $sid,
            'AccountSid' => 'AC'.str_repeat('a', 32),
            'Direction' => 'inbound',
            'From' => $from,
            'To' => '+13055550104',
            'CallStatus' => 'ringing',
        ];

        return $this->post($url, $params, [
            'X-Twilio-Signature' => (new RequestValidator($authToken))
                ->computeSignature($url, $params),
        ])->assertOk()->getContent();
    }
}
