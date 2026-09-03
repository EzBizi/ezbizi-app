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
