<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Am I available — asked and answered about the PERSON, not a connection.
 *
 * Presence is stored per connection because a call arrives on one. The control
 * that shows it lives in the app bar, which has no connection in hand and no
 * business learning about one: somebody stepping away is stepping away from
 * all of it.
 */
class PhoneAvailabilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A desk on two separate phone accounts, which is the case that matters. */
    private function deskOnTwoCompanies(array $t): array
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $user = $this->userWithRole($t, 'Dispatch', ['phone.view'], 'd@t.test');

            // One connection per place, enforced by a unique key on
            // (organization, scope_type, scope_id) — so two accounts means two
            // PLACES, which is the shape a dispatcher covering two businesses
            // actually has.
            $places = [
                [PhoneConnection::SCOPE_ORGANIZATION, $t['org']->id, '+12035557001'],
                [PhoneConnection::SCOPE_COMPANY, $t['company']->id, '+13055557002'],
            ];

            $rows = [];
            foreach ($places as [$scopeType, $scopeId, $e164]) {
                $connection = PhoneConnection::factory()->create([
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'status' => PhoneConnection::STATUS_CONNECTED,
                ]);

                $number = PhoneNumber::factory()->create([
                    'connection_id' => $connection->id,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'e164' => $e164,
                ]);

                PhoneNumberGrant::query()->create([
                    'number_id' => $number->id,
                    'role_id' => $user->role_id,
                ]);

                $rows[] = PhonePresence::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                    'state' => PhonePresence::STATE_REGISTERED,
                ]);
            }

            return [$user, $rows];
        });
    }

    /**
     * Stepping away steps away from EVERY line.
     *
     * Setting it per connection means a dispatcher covering three companies
     * has to say it three times and can leave one behind — and the one left
     * behind is the one that rings.
     */
    public function test_going_unavailable_covers_every_line_at_once(): void
    {
        $t = $this->provisionTenant();
        [$user] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk()
            ->assertJsonPath('data.lines', 2);

        $this->inTenant($t['org'], function () use ($user) {
            $states = PhonePresence::query()
                ->where('user_id', $user->id)
                ->pluck('state')
                ->all();

            $this->assertSame(['dnd', 'dnd'], $states);
        });
    }

    /**
     * The answer is the STRICTEST state held anywhere.
     *
     * Available on one line and Do Not Disturb on another is not available in
     * any sense the app bar can usefully draw, and showing green because one
     * row says so is the reassuring half of a split answer.
     */
    public function test_availability_reports_the_strictest_state_held(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->inTenant($t['org'], fn () => $rows[1]
            ->forceFill(['state' => PhonePresence::STATE_DND])->save());

        $this->signedInAs($user)
            ->getJson('/api/v1/phone/presence/me')
            ->assertOk()
            ->assertJsonPath('data.state', 'dnd')
            ->assertJsonPath('data.has_line', true);
    }

    /**
     * A desk coming up does not cancel Do Not Disturb.
     *
     * ── The report ───────────────────────────────────────────────
     *
     * Jose set himself to Not taking calls and took a call five minutes
     * later. Measured on production before changing anything:
     *
     *     EzBizi        dnd          19:39:28
     *     A-1 Chimney   registered   19:44:35
     *
     * `setMine` had done its job — both rows went to `dnd` at 19:39. What
     * undid it was `PhoneCallService::start()`, which ends by PUTting
     * `registered` for the connection it has just brought up, and which runs
     * on every app launch, every reconnect, and every switch between
     * companies. None of those is a person saying they are ready.
     *
     * The call then arrived on the line whose row had been quietly put back.
     */
    public function test_a_desk_registering_does_not_cancel_do_not_disturb(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        // The person says it once, for everything, the way the app bar does.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk();

        // Then a desk comes up on ONE of the companies — an app launch, a
        // reconnect, a scope switch. This is the exact call start() makes.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => 'registered',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'dnd');

        $this->inTenant($t['org'], function () use ($user) {
            $this->assertSame(
                ['dnd', 'dnd'],
                PhonePresence::query()->where('user_id', $user->id)->pluck('state')->all(),
                'a line went back to taking calls without anybody asking it to',
            );
        });
    }

    /**
     * And a desk coming up for somebody who is NOT away still registers them.
     *
     * The control. Without it, a rule that simply refused every `registered`
     * would pass the test above and leave every desk unable to report itself
     * present — the whole ring group unreachable, silently, which is worse
     * than the bug being fixed.
     */
    public function test_a_desk_registering_normally_still_registers(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->inTenant($t['org'], fn () => $rows[0]
            ->forceFill(['state' => PhonePresence::STATE_ON_CALL])->save());

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => 'registered',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'registered');
    }

    /**
     * The toggle is still the way back, and it is the only way back.
     *
     * The rule above is about WHO is speaking rather than what was sent, so
     * the half that matters is that a person choosing still wins. Without
     * this, "a desk cannot clear dnd" and "nothing can clear dnd" are the
     * same green, and somebody is stuck away until they reinstall.
     */
    public function test_the_person_can_still_come_back(): void
    {
        $t = $this->provisionTenant();
        [$user] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk();

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'registered'])
            ->assertOk()
            ->assertJsonPath('data.lines', 2);

        $this->inTenant($t['org'], function () use ($user) {
            $this->assertSame(
                ['registered', 'registered'],
                PhonePresence::query()->where('user_id', $user->id)->pluck('state')->all(),
            );
        });
    }

    /**
     * A live call is not something a toggle overwrites.
     *
     * `on_call` is set by the desk when audio is actually flowing. Letting
     * "available" clear it would mark somebody free while they are talking,
     * and the next call would ring straight into the one they are on.
     */
    public function test_becoming_available_does_not_clear_a_live_call(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->inTenant($t['org'], fn () => $rows[0]
            ->forceFill(['state' => PhonePresence::STATE_ON_CALL])->save());

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'registered'])
            ->assertOk()
            ->assertJsonPath('data.lines', 1);

        $this->inTenant($t['org'], fn () => $this->assertSame(
            PhonePresence::STATE_ON_CALL,
            $rows[0]->refresh()->state,
            'a toggle marked somebody free while they were on a call',
        ));
    }

    /**
     * Somebody with no phone line gets `has_line: false` rather than a
     * cheerful "available" — the app bar uses it to draw nothing at all,
     * because an availability light for a person with no phone is a control
     * that cannot mean anything.
     */
    public function test_somebody_with_no_line_is_reported_as_having_none(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'No phone', ['phone.view'], 'np@t.test');

        $this->signedInAs($user)
            ->getJson('/api/v1/phone/presence/me')
            ->assertOk()
            ->assertJsonPath('data.has_line', false);
    }
}
