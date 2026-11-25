<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\DialPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Every device a person has open rings, not just one of them.
 *
 * ── What was measured ────────────────────────────────────────────
 *
 * `<Dial>` makes exactly ONE call leg per `<Client>` noun. Read off three real
 * inbound calls on 2026-09-10: three legs each, one per member of the ring
 * group, never two for the same person. Jose had the desk open on a PC and the
 * app on an iPhone, both registered — and only the iPhone rang.
 *
 * Twilio's `<Client>` documentation says nothing about fanning one identity
 * out across endpoints, and the "ten devices all ring" material is about SIP
 * registration, a different mechanism entirely. So this does the documented
 * thing instead: name each device, up to ten nouns in a Dial, first to accept
 * wins.
 */
class PhoneDeviceRingingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneRingGroup, 3: \App\Models\User} */
    private function desk(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Two devices', ['phone.view']);

        [$connection, $group] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

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
                'e164' => '+13055550115',
                'ring_group_id' => $group->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$connection, $group];
        });

        return [$t, $connection, $group, $desk];
    }

    private function device(
        array $t,
        PhoneConnection $connection,
        $user,
        string $deviceId,
        ?Carbon $seen = null,
    ): void {
        $this->inTenant($t['org'], fn () => PhoneDeviceRegistration::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'label' => $deviceId,
            'last_seen_at' => $seen ?? now(),
        ]));
    }

    /** @return list<string> every identity a call to this group would dial */
    private function dialled(array $t, PhoneRingGroup $group, PhoneConnection $connection): array
    {
        return $this->inTenant($t['org'], fn () => array_merge(
            ...DialPlan::batches($group->fresh(), $connection),
        ));
    }

    public function test_both_of_a_persons_devices_are_dialled(): void
    {
        [$t, $connection, $group, $desk] = $this->desk();

        $this->device($t, $connection, $desk, 'aaaa1111');
        $this->device($t, $connection, $desk, 'bbbb2222');

        $dialled = $this->dialled($t, $group, $connection);

        $this->assertCount(
            2,
            $dialled,
            'one person with two devices produced '.count($dialled).' leg(s), so '
            .'only one of their devices can ring',
        );

        $this->assertContains(ClientIdentity::of($desk, 'aaaa1111'), $dialled);
        $this->assertContains(ClientIdentity::of($desk, 'bbbb2222'), $dialled);
    }

    /**
     * A person with no device row still rings.
     *
     * This is the shape every client had until today, and a build that has not
     * been updated still sends no device id. Dropping them would take the
     * phone away from everyone mid-rollout.
     */
    public function test_a_person_with_no_registered_device_still_rings(): void
    {
        [$t, $connection, $group, $desk] = $this->desk();

        $this->assertSame(
            [ClientIdentity::of($desk)],
            $this->dialled($t, $group, $connection),
        );
    }

    /**
     * A device that stopped asking for tokens stops being dialled.
     *
     * Without this a closed browser tab holds one of the ten `<Client>` slots
     * for ever and rings nothing — and the caller waits out the timeout for an
     * endpoint that is not there. Read from a deadline, so nothing has to run
     * to clean it up.
     */
    public function test_a_stale_device_is_not_dialled(): void
    {
        [$t, $connection, $group, $desk] = $this->desk();

        $this->device($t, $connection, $desk, 'aaaa1111');
        $this->device(
            $t,
            $connection,
            $desk,
            'ffff9999',
            now()->subMinutes(PhoneDeviceRegistration::STALE_AFTER_MINUTES + 5),
        );

        $dialled = $this->dialled($t, $group, $connection);

        $this->assertSame([ClientIdentity::of($desk, 'aaaa1111')], $dialled);
    }

    /** Asking for a token records the device, and names it in the identity. */
    public function test_a_token_request_registers_the_device_that_asked(): void
    {
        [$t, $connection, , $desk] = $this->desk();

        $identity = $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
            'device_id' => 'cccc3333',
            'device_label' => 'Windows',
        ])->assertOk()->json('identity');

        $this->assertSame(ClientIdentity::of($desk, 'cccc3333'), $identity);

        $row = $this->inTenant($t['org'], fn () => PhoneDeviceRegistration::query()
            ->where('device_id', 'cccc3333')
            ->first());

        $this->assertNotNull($row, 'the device asked for a token and was not recorded');
        $this->assertSame('Windows', $row->label);
        $this->assertSame($desk->id, $row->user_id);
    }

    /**
     * The same device asking twice is one row with a fresher clock.
     *
     * A row per token request would fill the dial list with the same install
     * over and over, and ten of them would leave no room for anybody else.
     */
    public function test_asking_twice_refreshes_rather_than_duplicates(): void
    {
        [$t, $connection, , $desk] = $this->desk();

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
            'device_id' => 'cccc3333',
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-10 11:00:00'));
        $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
            'device_id' => 'cccc3333',
        ])->assertOk();

        $rows = $this->inTenant($t['org'], fn () => PhoneDeviceRegistration::query()
            ->where('device_id', 'cccc3333')
            ->get());

        $this->assertCount(1, $rows);
        $this->assertSame('11:00:00', $rows->first()->last_seen_at->format('H:i:s'));
    }

    /** An older client sends no device id and is recorded as no device. */
    public function test_a_client_that_sends_no_device_id_still_works(): void
    {
        [$t, $connection, , $desk] = $this->desk();

        $identity = $this->signedInAs($desk)->postJson('/api/v1/phone/tokens', [
            'connection_id' => $connection->id,
        ])->assertOk()->json('identity');

        $this->assertSame(ClientIdentity::of($desk), $identity);
        $this->assertStringNotContainsString('_', $identity);

        $this->assertSame(0, $this->inTenant(
            $t['org'],
            fn () => PhoneDeviceRegistration::query()->count(),
        ));
    }

    /** An identity still says whose it is, with or without a device. */
    public function test_an_identity_names_its_person_either_way(): void
    {
        [, , , $desk] = $this->desk();

        $this->assertTrue(ClientIdentity::belongsTo(ClientIdentity::of($desk), $desk));
        $this->assertTrue(
            ClientIdentity::belongsTo(ClientIdentity::of($desk, 'cccc3333'), $desk),
            'a device suffix stopped the identity being recognised as this '
            .'person, which is what an extension uses to find their caller ID',
        );
    }
}
