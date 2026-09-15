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
     * `registered` for the connection it has just brought up.
     *
     * It runs once per app process — `if (_started || _starting) return;` on a
     * singleton — so the trigger is an app launch, a sign-in, or the "Try
     * again" button, and NOT a company switch or a recovered socket. That
     * distinction is here because the first version of this docblock claimed
     * all three and the deploy desk was about to print it for users.
     *
     * None of those is a person saying they are ready.
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
     * A desk starting somewhere NEW cannot make an away person available.
     *
     * ── The third report, and the one the guards could not reach ─────
     *
     * Jose: web on Do Not Disturb, desktop showing available, and a call
     * rang him anyway. Measured on his account before changing anything —
     * **two** presence rows across **four** connections, both `dnd`.
     *
     * That is the mechanism. `setMine` sets every row the person HAS, and a
     * desk opening on a connection they have never used calls
     * `updateOrCreate`, finds nothing, and creates one. A new row has no
     * previous state, so a guard that compares against the previous state
     * cannot fire, and the row is born taking calls.
     *
     * His words, and they name the fix better than the code did: it should
     * put my entire account on Do Not Disturb, not just my current session.
     */
    public function test_a_desk_on_a_new_connection_is_born_away(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk();

        // A THIRD place, which this person has never opened the phone on — so
        // `setMine` had nothing to set there and the row does not exist.
        $third = $this->inTenant($t['org'], function () use ($t, $user) {
            // A DIFFERENT company. One connection per place is enforced by a
            // unique key, so reusing the fixture's own company is a duplicate
            // rather than a third place — which is what this test is about.
            $elsewhere = \App\Models\Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'A second business',
            ]);

            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $elsewhere->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $elsewhere->id,
                'e164' => '+13055557003',
            ]);

            PhoneNumberGrant::query()->create([
                'number_id' => $number->id,
                'role_id' => $user->role_id,
            ]);

            return $connection;
        });

        $this->assertSame(
            2,
            $this->inTenant($t['org'], fn () => PhonePresence::query()
                ->where('user_id', $user->id)->count()),
            'the fixture already has a row here, so this tests nothing',
        );

        // The desk coming up there. This is the call start() makes.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $third->id,
                'state' => 'registered',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'dnd');

        $this->inTenant($t['org'], function () use ($user) {
            $this->assertSame(
                ['dnd', 'dnd', 'dnd'],
                PhonePresence::query()->where('user_id', $user->id)
                    ->pluck('state')->all(),
                'opening the app somewhere new put this person back on the rota',
            );
        });
    }

    /**
     * And the ring group agrees, which is the thing that actually rang him.
     *
     * The assertion above is about a column. A caller does not read columns:
     * they reach whoever `reachableUserIds` names, and a green column with a
     * dial that names you anyway is exactly what produced the report.
     */
    public function test_a_newly_created_row_does_not_make_somebody_dialled(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])->assertOk();

        $third = $this->inTenant($t['org'], function () use ($t) {
            $elsewhere = \App\Models\Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'A second business',
            ]);

            return PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $elsewhere->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
        });

        $this->inTenant($t['org'], fn () => PhonePresence::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $third->id,
            'user_id' => $user->id,
            'state' => PhonePresence::STATE_REGISTERED,
        ]));

        // Written straight into the table, which is the state the bug LEFT
        // BEHIND on Jose's account. Reaching it through the endpoint would
        // now be impossible, and this asserts the router refuses it anyway.
        $this->inTenant($t['org'], function () use ($user, $third, $t) {
            $group = \App\Models\PhoneRingGroup::query()->create([
                'connection_id' => $third->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$user->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $connection = PhoneConnection::query()->whereKey($third->id)->firstOrFail();

            $this->assertSame(
                [$user->id],
                \App\Support\Phone\DialPlan::reachableUserIds($group, $connection),
                'this is the state the defect left behind, and it must still '
                .'ring — the fix is that the endpoint cannot CREATE it',
            );
        });
    }

    /**
     * Ending a call does not bring back somebody who is away elsewhere.
     *
     * The case `setMine` skips on purpose: it will not overwrite a live call,
     * so a row can sit at `on_call` while every other row is `dnd`. When the
     * call ended the desk wrote `registered`, the previous state was
     * `on_call` rather than `dnd`, and the guard let it through — the person
     * came back on the rota having pressed nothing.
     */
    public function test_a_call_ending_elsewhere_does_not_undo_being_away(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        // On a call here, away everywhere else.
        $this->inTenant($t['org'], fn () => $rows[0]
            ->forceFill(['state' => PhonePresence::STATE_ON_CALL])->save());
        $this->inTenant($t['org'], fn () => $rows[1]
            ->forceFill(['state' => PhonePresence::STATE_DND])->save());

        // The call ends.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => 'registered',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'dnd');
    }

    /**
     * NO state the desk can send changes a standing decision.
     *
     * The two tests below walk the routes that were actually reported. This
     * one walks the whole set the endpoint accepts, because both of the
     * narrow guards that came before were correct about the route somebody
     * had hit and silent about the next one. `wrap_up` is in this list and no
     * reported bug has ever gone through it; that is the point.
     *
     * If a fifth state is ever added to the validator, this fails until
     * somebody decides what it means for a person who is away — which is the
     * conversation the last two fixes each skipped.
     */
    public function test_no_state_the_desk_sends_can_clear_do_not_disturb(): void
    {
        foreach (['registered', 'on_call', 'wrap_up', 'dnd'] as $state) {
            $t = $this->provisionTenant();
            [$user, $rows] = $this->deskOnTwoCompanies($t);

            $this->signedInAs($user)
                ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
                ->assertOk();

            $this->signedInAs($user)
                ->putJson('/api/v1/phone/presence', [
                    'connection_id' => $rows[0]->connection_id,
                    'state' => $state,
                ])
                ->assertOk()
                ->assertJsonPath('data.state', 'dnd');

            $this->inTenant($t['org'], function () use ($user, $state) {
                $this->assertSame(
                    ['dnd', 'dnd'],
                    PhonePresence::query()->where('user_id', $user->id)->pluck('state')->all(),
                    "the desk sending '{$state}' took somebody off Do Not Disturb",
                );
            });
        }
    }

    /**
     * Making a call while away does not bring you back.
     *
     * ── The second report, and why the first fix missed it ───────
     *
     * Jose reported the same bug two days after it was fixed. The database
     * could not answer it — presence keeps no audit trail, and `updated_at`
     * says when a row was last written, not what it held a minute earlier.
     * Twilio could: a `<Dial>` makes one CHILD call per `<Client>` noun, and
     * the children of the call he reported named six people, him among them.
     * So he was reachable when it rang, fourteen seconds before his rows read
     * `dnd`.
     *
     * The route is this one. Do Not Disturb stops calls ARRIVING; it does not
     * stop you placing one. The desk writes `on_call` when you dial out, which
     * lands on top of `dnd` and erases the fact that a decision was made —
     * and then the call ending writes `registered`, which the first guard
     * waved through because the stored state was no longer `dnd`.
     *
     * This walks the whole route rather than asserting the rule, because the
     * rule read correctly and the route still ended up available.
     */
    public function test_placing_a_call_while_away_does_not_bring_you_back(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])
            ->assertOk();

        // Dialling out. The desk sends this the moment the call connects.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => 'on_call',
            ])
            ->assertOk();

        // Hanging up. `_releasePresence()` sends this for any call that was
        // connected, with `registered` hard-coded.
        $this->signedInAs($user)
            ->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => 'registered',
            ])
            ->assertOk();

        $this->inTenant($t['org'], function () use ($user) {
            $this->assertSame(
                ['dnd', 'dnd'],
                PhonePresence::query()->where('user_id', $user->id)->pluck('state')->all(),
                'ringing one customer put this person back on the rota',
            );
        });
    }

    /**
     * And the ring group agrees, which is the thing that actually rings.
     *
     * The assertions above are about a column. This one asks the code a real
     * call asks — a green column and a dial that names you anyway is the exact
     * pair that produced the second report.
     */
    public function test_somebody_away_is_not_dialled_after_a_call_of_their_own(): void
    {
        $t = $this->provisionTenant();
        [$user, $rows] = $this->deskOnTwoCompanies($t);

        $this->signedInAs($user)->putJson('/api/v1/phone/presence/me', ['state' => 'dnd'])->assertOk();

        foreach (['on_call', 'registered'] as $state) {
            $this->signedInAs($user)->putJson('/api/v1/phone/presence', [
                'connection_id' => $rows[0]->connection_id,
                'state' => $state,
            ])->assertOk();
        }

        $this->inTenant($t['org'], function () use ($user, $rows) {
            $connection = \App\Models\PhoneConnection::query()
                ->whereKey($rows[0]->connection_id)->firstOrFail();

            $group = \App\Models\PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Existing Client',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$user->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $this->assertSame(
                [],
                \App\Support\Phone\DialPlan::reachableUserIds($group, $connection),
                'a caller would be put through to somebody who is not taking calls',
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
