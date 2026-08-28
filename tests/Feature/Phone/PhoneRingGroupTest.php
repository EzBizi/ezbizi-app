<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Ring group CRUD, and attaching one to a line (spec §7, decision 5).
 *
 * The engine — DialPlan expansion, the ten-per-Dial cap, overflow — was
 * already built and covered by DialPlanTest. What was missing was any way to
 * CREATE a group or point a number at one, so `ring_group_id` was permanently
 * null and TwimlRenderer sent every inbound call to voicemail. The last test
 * here is the one that would have caught that.
 */
class PhoneRingGroupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_group_is_created_and_listed(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => $this->connection($t));

        $created = $this->signedInAs($editor)->postJson('/api/v1/phone/ring-groups', [
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'sequential',
            'timeout_seconds' => 25,
            'member_user_ids' => [$editor->id],
        ])->assertCreated();

        $this->assertSame('Front desk', $created->json('data.name'));
        $this->assertSame('sequential', $created->json('data.mode'));
        $this->assertSame(25, $created->json('data.timeout_seconds'));

        $this->signedInAs($editor)->getJson('/api/v1/phone/ring-groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Front desk');
    }

    public function test_phone_view_alone_cannot_create_a_group(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view']);

        $connection = $this->inTenant($t['org'], fn () => $this->connection($t));

        $response = $this->signedInAs($viewer)->postJson('/api/v1/phone/ring-groups', [
            'connection_id' => $connection->id,
            'name' => 'Front desk',
        ])->assertForbidden();

        $message = (string) $response->json('message');
        $this->assertStringContainsString('ring groups', $message);
        $this->assertStringNotContainsString('PhoneRingGroup', $message);
    }

    /**
     * An overflow naming nothing renders as a plain voicemail, which looks like
     * it worked. The only place anybody finds out is the save.
     */
    public function test_forwarding_overflow_without_a_number_is_refused_in_a_sentence(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => $this->connection($t));

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/ring-groups', [
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'overflow' => 'forward',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'which number',
            (string) $response->json('errors.overflow_number.0'),
        );
    }

    public function test_a_group_still_answering_a_line_cannot_be_deleted(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        [$group, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $group = $this->group($connection);
            $number = $this->number($t, $connection, ringGroupId: $group->id);

            return [$group, $number];
        });

        $response = $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/ring-groups/'.$group->id)
            ->assertStatus(422);

        $this->assertStringContainsString(
            $number->e164,
            (string) $response->json('errors.ring_group.0'),
        );
    }

    public function test_a_line_cannot_point_at_a_group_on_another_phone_account(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        [$number, $foreignGroup] = $this->inTenant($t['org'], function () use ($t) {
            $mine = $this->connection($t);
            $other = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
            ]);

            return [$this->number($t, $mine), $this->group($other, name: 'Their desk')];
        });

        $response = $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, [
                'ring_group_id' => $foreignGroup->id,
            ])->assertStatus(422);

        $this->assertStringContainsString(
            'different phone account',
            (string) $response->json('errors.ring_group_id.0'),
        );
    }

    /**
     * The end-to-end point of the whole feature.
     *
     * A line with a group whose member is registered must produce a <Dial> that
     * names that person. Before ring groups could be created this rendered as
     * <Record> — a voicemail — on a fully configured account, and nothing said
     * why.
     */
    public function test_a_line_with_a_group_rings_a_person_instead_of_voicemail(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $member = $this->member($t, 'Answerer');

            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $member->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            $group = $this->group($connection, [$member->id]);
            $number = $this->number($t, $connection, ringGroupId: $group->id);

            return app(\App\Support\Phone\TwimlRenderer::class)
                ->inbound($number, ['from' => '+15550001111']);
        });

        $identity = str_replace('-', '', (string) $this->inTenant(
            $t['org'],
            fn () => User::query()->where('name', 'Answerer')->value('id'),
        ));

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString($identity, $xml);
        $this->assertStringNotContainsString('<Record', $xml);
    }

    /** @param array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise} $t */
    private function connection(array $t): PhoneConnection
    {
        return PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]);
    }

    /** @param list<string> $userIds */
    private function group(PhoneConnection $connection, array $userIds = [], string $name = 'Front desk'): PhoneRingGroup
    {
        return PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => $name,
            'mode' => PhoneRingGroup::MODE_SIMULTANEOUS,
            'timeout_seconds' => 30,
            'member_user_ids' => $userIds,
            'member_role_ids' => [],
            'overflow' => PhoneRingGroup::OVERFLOW_VOICEMAIL,
        ]);
    }

    private function number(array $t, PhoneConnection $connection, ?string $ringGroupId = null): PhoneNumber
    {
        return PhoneNumber::query()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'twilio_sid' => 'PN'.str_repeat('a', 32),
            'e164' => '+12035550123',
            'label' => 'Front desk',
            'after_hours' => PhoneNumber::AFTER_HOURS_VOICEMAIL,
            'ring_group_id' => $ringGroupId,
        ]);
    }

    private function member(array $t, string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['organization_id' => $t['org']->id, 'name' => 'Dial members'],
            ['is_system' => false],
        );

        return User::create([
            'organization_id' => $t['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower($name).'@'.$t['org']->slug.'.test',
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
    }
}
