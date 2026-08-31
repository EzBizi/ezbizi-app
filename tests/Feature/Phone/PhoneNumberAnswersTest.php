<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `answers` on a number row must mean what the renderer means by it.
 *
 * It was computed in the controller as `ring_group_id !== null` — a second
 * rule, and a weaker one. A group whose every member is at Do Not Disturb
 * satisfies it and answers no calls, which is precisely the state that looks
 * perfectly configured on screen while every caller hears voicemail.
 *
 * The Numbers screen paints this field red and the Call flow screen counts it,
 * so a payload that disagrees with the renderer makes both of them liars.
 */
class PhoneNumberAnswersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_group_nobody_is_available_in_does_not_count_as_answering(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view']);

        $ids = $this->inTenant($t['org'], function () use ($t, $viewer) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $role = Role::query()->firstOrCreate(
                ['organization_id' => $t['org']->id, 'name' => 'Dial members'],
                ['is_system' => false],
            );

            $make = function (string $name, string $state) use ($t, $role, $connection) {
                $user = User::create([
                    'organization_id' => $t['org']->id,
                    'role_id' => $role->id,
                    'name' => $name,
                    'email' => strtolower($name).'@'.$t['org']->slug.'.test',
                    'password' => 'a-real-password',
                    'is_active' => true,
                ]);

                PhonePresence::query()->create([
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                    'state' => $state,
                ]);

                return PhoneRingGroup::query()->create([
                    'connection_id' => $connection->id,
                    'name' => $name.' group',
                    'mode' => 'simultaneous',
                    'timeout_seconds' => 30,
                    'member_user_ids' => [$user->id],
                    'member_role_ids' => [],
                    'overflow' => 'voicemail',
                ]);
            };

            $awayGroup = $make('Away', PhonePresence::STATE_DND);
            $deskGroup = $make('Desk', PhonePresence::STATE_REGISTERED);

            $silent = PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'twilio_sid' => 'PN'.str_repeat('a', 32),
                'e164' => '+15615550001',
                'label' => 'Silent',
                'ring_group_id' => $awayGroup->id,
                'after_hours' => 'voicemail',
            ]);

            $live = PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'twilio_sid' => 'PN'.str_repeat('b', 32),
                'e164' => '+15615550002',
                'label' => 'Live',
                'ring_group_id' => $deskGroup->id,
                'after_hours' => 'voicemail',
            ]);

            // Numbers are grant-scoped: without these the viewer sees an
            // empty list and the assertions below would pass vacuously.
            foreach ([$silent, $live] as $row) {
                PhoneNumberGrant::query()->create([
                    'number_id' => $row->id,
                    'role_id' => $viewer->role_id,
                ]);
            }

            return ['silent' => $silent->id, 'live' => $live->id];
        });

        $rows = collect(
            $this->signedInAs($viewer)->getJson('/api/v1/phone/numbers')->assertOk()->json('data')
        )->keyBy('id');

        $this->assertFalse(
            $rows[$ids['silent']]['answers'],
            'a group with nobody available answers no calls, and the row said it did',
        );
        $this->assertTrue($rows[$ids['live']]['answers']);
    }
}
