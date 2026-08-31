<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\DialPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Ring-group expansion: available Clients, capped at ten per batch (Task 8).
 *
 * Available means presence on this connection in state registered — not dnd,
 * not on_call, and not missing a row.
 */
class DialPlanTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_client_identity_is_the_user_id_without_hyphens(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Desk', ['phone.view']);

        $identity = ClientIdentity::of($user);

        $this->assertSame(str_replace('-', '', (string) $user->id), $identity);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $identity);
        $this->assertStringNotContainsString('-', $identity);
    }

    /**
     * The screen's column and the dialler must name the same people.
     *
     * Ring groups draws "who can be rung" from `roster()`, and a real call is
     * placed from `batches()`. Two derivations of "available" would agree on
     * the day they were written and drift the first time either changed —
     * which is the failure this module has now hit six times, and the reason
     * `reachableUserIds()` exists for both to call.
     *
     * The mixed group matters. All-registered or all-unavailable would pass
     * against two implementations that disagreed about `on_call`.
     */
    public function test_the_roster_names_exactly_who_would_be_dialled(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $registered = $this->member($t, 'Registered');
            $dnd = $this->member($t, 'Do not disturb');
            $onCall = $this->member($t, 'On a call');
            $silent = $this->member($t, 'Never registered');

            $this->presence($connection, $registered, PhonePresence::STATE_REGISTERED);
            $this->presence($connection, $dnd, PhonePresence::STATE_DND);
            $this->presence($connection, $onCall, PhonePresence::STATE_ON_CALL);

            $group = $this->group($connection, [
                $registered->id, $dnd->id, $onCall->id, $silent->id,
            ]);

            $roster = DialPlan::roster($group, $connection);

            $this->assertCount(4, $roster, 'a member vanished from the list');

            $saysReachable = collect($roster)
                ->filter(fn (array $row) => $row['reachable'])
                ->pluck('user_id')
                ->all();

            $wouldDial = array_map(
                fn (string $identity) => $identity,
                array_merge(...DialPlan::batches($group, $connection)),
            );

            $this->assertSame(
                array_map(fn ($id) => ClientIdentity::of(User::query()->find($id)), $saysReachable),
                $wouldDial,
                'the screen would show a different set of people than the call dials',
            );

            // And the reason is carried, because "not reachable" on its own
            // sends somebody to ask four people why their phone is off.
            $states = collect($roster)->pluck('state', 'name');
            $this->assertSame(PhonePresence::STATE_DND, $states['Do not disturb']);
            $this->assertSame(PhonePresence::STATE_ON_CALL, $states['On a call']);
            $this->assertNull(
                $states['Never registered'],
                'somebody who never opened Phone was reported as having a state',
            );
        });
    }

    public function test_unavailable_members_are_skipped(): void
    {
        $t = $this->provisionTenant();

        $batches = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $registered = $this->member($t, 'Registered');
            $dnd = $this->member($t, 'Do not disturb');
            $onCall = $this->member($t, 'On a call');
            $silent = $this->member($t, 'Never registered');

            $this->presence($connection, $registered, PhonePresence::STATE_REGISTERED);
            $this->presence($connection, $dnd, PhonePresence::STATE_DND);
            $this->presence($connection, $onCall, PhonePresence::STATE_ON_CALL);

            $group = $this->group($connection, [
                $registered->id,
                $dnd->id,
                $onCall->id,
                $silent->id,
            ]);

            return DialPlan::batches($group, $connection);
        });

        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]);
        $this->assertSame(
            str_replace('-', '', (string) $this->inTenant($t['org'], function () use ($t) {
                return User::query()->where('email', 'registered@'.$t['org']->slug.'.test')->value('id');
            })),
            $batches[0][0],
        );
    }

    public function test_presence_on_another_connection_does_not_count(): void
    {
        $t = $this->provisionTenant();

        $batches = $this->inTenant($t['org'], function () use ($t) {
            $ours = $this->connection($t);
            $other = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
            ]);
            $user = $this->member($t, 'Elsewhere');
            $this->presence($other, $user, PhonePresence::STATE_REGISTERED);

            $group = $this->group($ours, [$user->id]);

            return DialPlan::batches($group, $ours);
        });

        $this->assertSame([], $batches);
    }

    public function test_role_members_are_expanded_when_available(): void
    {
        $t = $this->provisionTenant();

        $batches = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $named = $this->member($t, 'Named');
            $fromRole = $this->member($t, 'From role');
            $this->presence($connection, $named, PhonePresence::STATE_REGISTERED);
            $this->presence($connection, $fromRole, PhonePresence::STATE_REGISTERED);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$named->id],
                'member_role_ids' => [$fromRole->role_id],
                'overflow' => 'voicemail',
            ]);

            return DialPlan::batches($group, $connection);
        });

        $this->assertCount(1, $batches);
        $this->assertCount(2, $batches[0]);
    }

    public function test_batches_cap_at_ten_clients(): void
    {
        $t = $this->provisionTenant();

        $batches = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $ids = [];
            for ($i = 0; $i < 11; $i++) {
                $user = $this->member($t, 'Member '.$i, 'member'.$i.'@'.$t['org']->slug.'.test');
                $this->presence($connection, $user, PhonePresence::STATE_REGISTERED);
                $ids[] = $user->id;
            }

            $group = $this->group($connection, $ids);

            return DialPlan::batches($group, $connection);
        });

        $this->assertCount(2, $batches);
        $this->assertCount(10, $batches[0]);
        $this->assertCount(1, $batches[1]);
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(10, count($batch));
        }
    }

    public function test_zero_available_members_yields_no_batches(): void
    {
        $t = $this->provisionTenant();

        $batches = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $user = $this->member($t, 'Away');
            $this->presence($connection, $user, PhonePresence::STATE_DND);
            $group = $this->group($connection, [$user->id]);

            return DialPlan::batches($group, $connection);
        });

        $this->assertSame([], $batches);
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     */
    private function connection(array $tenant): PhoneConnection
    {
        return PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
        ]);
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     */
    private function member(array $tenant, string $name, ?string $email = null): User
    {
        $email ??= strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test';

        $role = Role::query()->firstOrCreate(
            ['organization_id' => $tenant['org']->id, 'name' => 'Dial members'],
            ['is_system' => false],
        );

        return User::create([
            'organization_id' => $tenant['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => $email,
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
    }

    private function presence(PhoneConnection $connection, User $user, string $state): PhonePresence
    {
        return PhonePresence::query()->create([
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'state' => $state,
        ]);
    }

    /**
     * @param  list<string>  $userIds
     */
    private function group(PhoneConnection $connection, array $userIds): PhoneRingGroup
    {
        return PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => $userIds,
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]);
    }
}
