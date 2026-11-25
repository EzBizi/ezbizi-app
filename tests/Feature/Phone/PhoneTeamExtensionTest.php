<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneExtension;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An extension that reaches a team.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"On Settings i want a new tab called Extensions, so i can
 * assign extensions to users, ivr menus, ring groups"*.
 *
 * People already worked — eleven extensions are assigned in production. This
 * is the second of the three.
 */
class PhoneTeamExtensionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Dialling a team's extension rings everybody available on it.
     *
     * Two members, both available, so a build that fell through to the old
     * one-person path would name at most one of them — and one that rang the
     * LINE's own group rather than the extension's would name the wrong team
     * entirely, which is why the line's group is a different set of people.
     */
    public function test_a_team_extension_rings_that_team(): void
    {
        [$t, $number, $alice, $bruno, $carla] = $this->line();

        $xml = $this->dial($t, $number, '3');

        $this->assertStringContainsString(ClientIdentity::of($alice, 'alice-desk'), $xml);
        $this->assertStringContainsString(ClientIdentity::of($bruno, 'bruno-desk'), $xml);

        // On the LINE's group, not the extension's. Reaching her would mean
        // the extension was ignored and the line answered as usual.
        $this->assertStringNotContainsString(ClientIdentity::of($carla, 'carla-desk'), $xml);
    }

    /**
     * A person's extension still rings only that person.
     *
     * The control. Without it the case above passes on a build that rings
     * every available person for any extension at all, and people are the two
     * thirds of this feature that already worked.
     */
    public function test_a_person_extension_still_rings_one_person(): void
    {
        [$t, $number, $alice, $bruno] = $this->line();

        $xml = $this->dial($t, $number, '2');

        $this->assertStringContainsString(ClientIdentity::of($alice, 'alice-desk'), $xml);
        $this->assertStringNotContainsString(ClientIdentity::of($bruno, 'bruno-desk'), $xml);
    }

    /**
     * Digits that outlived the team they pointed at say so.
     *
     * `nullOnDelete` on purpose: deleting a person takes their extension with
     * them, because the digits meant that person. Deleting a TEAM leaves the
     * digits published — somebody has told customers to dial 300 — so the row
     * survives pointing at nothing.
     *
     * WHICH PATH ANSWERS IT is the part I had wrong. I expected the ring-group
     * arm to find a null group and say so; the foreign key nulls the COLUMN,
     * so the row has no target kind at all and never enters that arm. The
     * check I had written there could not fire, and deleting it is what this
     * test now pins.
     */
    public function test_an_extension_whose_team_was_deleted_says_so(): void
    {
        [$t, $number] = $this->line();

        $this->inTenant($t['org'], fn () => PhoneRingGroup::query()
            ->where('name', 'Appointments')
            ->delete());

        $xml = $this->dial($t, $number, '3');

        $this->assertStringContainsString('not set up right now', $xml);
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
    }

    private function dial(array $t, PhoneNumber $number, string $digits): string
    {
        return $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound(
            $number->fresh(),
            ['digits' => $digits],
        ));
    }

    /** @return array{0: array<string,mixed>, 1: PhoneNumber, 2: User, 3: User, 4: User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $alice = $this->userWithRole($t, 'Alice Nolan', ['phone.view']);
        $bruno = $this->userWithRole($t, 'Bruno Vega', ['phone.view']);
        $carla = $this->userWithRole($t, 'Carla Ruiz', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $alice, $bruno, $carla) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ([[$alice, 'alice-desk'], [$bruno, 'bruno-desk'], [$carla, 'carla-desk']] as [$person, $device]) {
                PhonePresence::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'state' => PhonePresence::STATE_REGISTERED,
                ]);

                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'device_id' => $device,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            $team = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Appointments',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$alice->id, $bruno->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            // A DIFFERENT set of people, so "rang the extension's team" and
            // "rang the line's team" cannot look alike.
            $lineGroup = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$carla->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Dial an extension, or hold for the desk.',
                    // A MENU KEY BOUND TO A FIXED EXTENSION, which is the only
                    // way `dialExtension` is reached today: there is no
                    // "type the extension you want" entry anywhere in the
                    // product. My first fixture dialled '0300' directly and
                    // every case failed identically, because an unrecognised
                    // key falls through to the line's own group.
                    'options' => [
                        ['digit' => '2', 'action' => 'extension', 'extension' => '0201'],
                        ['digit' => '3', 'action' => 'extension', 'extension' => '0300'],
                    ],
                ],
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'ring_group_id' => $lineGroup->id,
                'flow_id' => $flow->id,
            ]);

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $alice->id,
                'extension' => '0201',
            ]);

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'ring_group_id' => $team->id,
                'extension' => '0300',
            ]);

            return $number;
        });

        return [$t, $number, $alice, $bruno, $carla];
    }
}
