<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Support\Phone\DialPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * After-call wrap-up: a moment to finish the last call before the next.
 *
 * ── The problem ─────────────────────────────────────────────────
 *
 * The call ends and there is work left — the note, the tag, the follow-up.
 * With nothing in the way the next call arrives while that is half done, so
 * it gets abandoned, and the company's own rule that every call must be
 * tagged becomes a dialogue people dismiss.
 *
 * ── Why the deadline is the whole design ────────────────────────
 *
 * Availability is read from a TIME, not from a state something has to clear.
 * A wrap-up cleared by a job, a timer, or a callback from a client that may
 * have been closed is a wrap-up that gets stuck — and somebody stuck in it is
 * somebody the ring group quietly stops calling. That failure was reported on
 * this module today, on the on-call icon, which is why the expiry test below
 * matters more than the rest.
 */
class PhoneWrapUpTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_after_a_call_the_desk_is_left_alone_for_a_moment(): void
    {
        [$t, $connection, $group, $desk] = $this->desk(wrapUpSeconds: 30);

        $this->onACall($t, $connection, $desk);

        // The desk says the call is over, exactly as it does today.
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk()->assertJsonPath('data.state', PhonePresence::STATE_WRAP_UP);

        $this->assertSame([], $this->reachable($t, $group, $connection));
    }

    /**
     * The control, and the reason the setting exists rather than a constant.
     *
     * A company that has not asked for wrap-up gets none, and the desk is back
     * in the ring group the instant the call ends.
     */
    public function test_a_company_that_asked_for_none_gets_none(): void
    {
        [$t, $connection, $group, $desk] = $this->desk(wrapUpSeconds: 0);

        $this->onACall($t, $connection, $desk);

        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk()->assertJsonPath('data.state', PhonePresence::STATE_REGISTERED);

        $this->assertSame([$desk->id], $this->reachable($t, $group, $connection));
    }

    /**
     * It ends on its own, with nothing running.
     *
     * No job, no timer, no client callback. The deadline passes and the person
     * is reachable again, which is what makes a stuck wrap-up impossible
     * rather than merely unlikely.
     */
    public function test_the_wrap_up_expires_by_itself(): void
    {
        [$t, $connection, $group, $desk] = $this->desk(wrapUpSeconds: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-09 14:00:00'));

        $this->onACall($t, $connection, $desk);
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk();

        // Twenty-nine seconds later: still theirs.
        Carbon::setTestNow(Carbon::parse('2026-09-09 14:00:29'));
        $this->assertSame([], $this->reachable($t, $group, $connection));

        // Thirty-one: back in the group, and NOTHING RAN in between.
        Carbon::setTestNow(Carbon::parse('2026-09-09 14:00:31'));
        $this->assertSame([$desk->id], $this->reachable($t, $group, $connection));

        // The row still says wrap_up. That is the point: the stored state was
        // never corrected by anything, and availability did not need it to be.
        $this->assertSame(
            PhonePresence::STATE_WRAP_UP,
            $this->inTenant($t['org'], fn () => PhonePresence::query()
                ->where('user_id', $desk->id)
                ->value('state')),
        );
    }

    /**
     * "I'm ready" wins immediately.
     *
     * Both transitions arrive as the same word — the desk sends "registered"
     * when a call ends and when somebody presses the button — so they are told
     * apart by the state already stored. Without that, the button would
     * restart the wrap-up it was pressed to end.
     */
    public function test_somebody_can_say_they_are_ready_early(): void
    {
        [$t, $connection, $group, $desk] = $this->desk(wrapUpSeconds: 120);

        $this->onACall($t, $connection, $desk);
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk()->assertJsonPath('data.state', PhonePresence::STATE_WRAP_UP);

        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk()
            ->assertJsonPath('data.state', PhonePresence::STATE_REGISTERED)
            ->assertJsonPath('data.wrap_up_until', null);

        $this->assertSame([$desk->id], $this->reachable($t, $group, $connection));
    }

    /**
     * Wrap-up does not survive a call that never happened.
     *
     * Somebody going straight from available to available keeps no deadline,
     * so a stale one cannot be waiting to take them out of the group later.
     */
    public function test_a_deadline_is_not_left_behind(): void
    {
        [$t, $connection, , $desk] = $this->desk(wrapUpSeconds: 60);

        foreach (['dnd', 'registered'] as $state) {
            $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
                'connection_id' => $connection->id,
                'state' => $state,
            ])->assertOk()->assertJsonPath('data.wrap_up_until', null);
        }
    }

    /** The bar at the top says what is happening, and stops saying it. */
    public function test_the_app_bar_shows_wrap_up_only_while_it_lasts(): void
    {
        [$t, $connection, , $desk] = $this->desk(wrapUpSeconds: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-09 15:00:00'));

        $this->onACall($t, $connection, $desk);
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk();

        $this->signedInAs($desk)->getJson('/api/v1/phone/presence/me')
            ->assertOk()
            ->assertJsonPath('data.state', PhonePresence::STATE_WRAP_UP);

        Carbon::setTestNow(Carbon::parse('2026-09-09 15:00:31'));

        $this->signedInAs($desk)->getJson('/api/v1/phone/presence/me')
            ->assertOk()
            ->assertJsonPath('data.state', PhonePresence::STATE_REGISTERED);
    }

    /**
     * The team list agrees with who the phone is actually ringing.
     *
     * ── The defect this was written for ─────────────────────────
     *
     * `index` reported the stored string. Availability is read from the
     * deadline, so the moment a wrap-up expired the two disagreed: routing
     * rang somebody the supervisor's screen showed as busy, indefinitely,
     * because nothing ever rewrites the row. A screen that contradicts the
     * routing is worse than no screen — it is where "the phone is broken"
     * reports come from.
     */
    public function test_the_team_list_stops_saying_wrap_up_when_it_ends(): void
    {
        [$t, $connection, $group, $desk] = $this->desk(wrapUpSeconds: 30);

        Carbon::setTestNow(Carbon::parse('2026-09-09 16:00:00'));

        $this->onACall($t, $connection, $desk);
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'registered',
        ])->assertOk();

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/presence?connection_id='.$connection->id)
            ->assertOk()
            ->assertJsonPath('data.0.state', PhonePresence::STATE_WRAP_UP);

        Carbon::setTestNow(Carbon::parse('2026-09-09 16:00:31'));

        // Reachable AND reported as reachable. Both, in one test, because the
        // defect was precisely that only one of them moved.
        $this->assertSame([$desk->id], $this->reachable($t, $group, $connection));

        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/presence?connection_id='.$connection->id)
            ->assertOk()
            ->assertJsonPath('data.0.state', PhonePresence::STATE_REGISTERED)
            ->assertJsonPath('data.0.wrap_up_until', null);
    }

    /** An hour of wrap-up is somebody's typo, not their intent. */
    public function test_an_absurd_wrap_up_is_refused(): void
    {
        [$t, $connection] = $this->desk(wrapUpSeconds: 0);
        $admin = $this->userWithRole($t, 'Sets it', ['phone.view', 'phone.edit']);

        $this->signedInAs($admin)->patchJson('/api/v1/phone/connections/'.$connection->id, [
            'wrap_up_seconds' => 3600,
        ])->assertStatus(422);

        $this->signedInAs($admin)->patchJson('/api/v1/phone/connections/'.$connection->id, [
            'wrap_up_seconds' => 45,
        ])->assertOk()->assertJsonPath('data.wrap_up_seconds', 45);
    }

    /** Put somebody on a call, the way the desk does. */
    private function onACall(array $t, PhoneConnection $connection, $desk): void
    {
        $this->signedInAs($desk)->putJson('/api/v1/phone/presence', [
            'connection_id' => $connection->id,
            'state' => 'on_call',
        ])->assertOk();
    }

    /** @return list<string> */
    private function reachable(array $t, PhoneRingGroup $group, PhoneConnection $connection): array
    {
        return $this->inTenant(
            $t['org'],
            fn () => DialPlan::reachableUserIds($group->fresh(), $connection),
        );
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneRingGroup, 3: \App\Models\User} */
    private function desk(int $wrapUpSeconds): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Wrap up desk', ['phone.view']);

        [$connection, $group] = $this->inTenant($t['org'], function () use ($t, $desk, $wrapUpSeconds) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'wrap_up_seconds' => $wrapUpSeconds,
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
                'e164' => '+13055550105',
                'ring_group_id' => $group->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            return [$connection, $group];
        });

        return [$t, $connection, $group, $desk];
    }
}
