<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneRingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Extensions screen: every set of digits, and what it reaches.
 *
 * Jose, 2026-09-17: *"On Settings i want a new tab called Extensions, so i can
 * assign extensions to users, ivr menus, ring groups"*.
 *
 * ── Why this is not the Users tab's endpoint ─────────────────────
 *
 * `index()` is PEOPLE-shaped and keys by `user_id`, which is right for a
 * roster and cannot hold a team's extension — every one of them would collapse
 * into a single null key and vanish. This is EXTENSION-shaped, because the
 * subject of this screen is a number somebody published to customers.
 */
class PhoneExtensionDirectoryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Both kinds of extension are listed, and named.
     *
     * The team row is the one that matters: it is invisible to the roster
     * endpoint, so a screen built on that would show the person and silently
     * omit the team.
     */
    public function test_the_directory_lists_people_and_teams(): void
    {
        [$t, $connection, $editor, $alice, $group] = $this->account();

        $this->inTenant($t['org'], function () use ($t, $connection, $alice, $group) {
            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $alice->id,
                'extension' => '0101',
            ]);

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'ring_group_id' => $group->id,
                'extension' => '0300',
            ]);
        });

        $this->signedInAs($editor)
            ->getJson('/api/v1/phone/extensions/directory')
            ->assertOk()
            ->assertJsonPath('data.0.extension', '0101')
            ->assertJsonPath('data.0.target_kind', PhoneExtension::TARGET_USER)
            ->assertJsonPath('data.0.target_name', 'Alice Nolan')
            ->assertJsonPath('data.1.extension', '0300')
            ->assertJsonPath('data.1.target_kind', PhoneExtension::TARGET_RING_GROUP)
            ->assertJsonPath('data.1.target_name', 'Appointments');
    }

    /**
     * Digits whose team was deleted are still listed, pointing at nothing.
     *
     * They were published to customers. Hiding them would mean the one screen
     * that could show somebody a dead extension is the screen that does not.
     */
    public function test_an_extension_that_lost_its_target_is_still_listed(): void
    {
        [$t, $connection, $editor, , $group] = $this->account();

        $this->inTenant($t['org'], function () use ($t, $connection, $group) {
            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'ring_group_id' => $group->id,
                'extension' => '0300',
            ]);

            PhoneRingGroup::query()->whereKey($group->id)->delete();
        });

        $this->signedInAs($editor)
            ->getJson('/api/v1/phone/extensions/directory')
            ->assertOk()
            ->assertJsonPath('data.0.extension', '0300')
            ->assertJsonPath('data.0.target_kind', null)
            ->assertJsonPath('data.0.target_name', null);
    }

    public function test_a_team_can_be_given_an_extension(): void
    {
        [$t, $connection, $editor, , $group] = $this->account();

        $this->signedInAs($editor)
            ->postJson('/api/v1/phone/extensions/directory', [
                'connection_id' => $connection->id,
                'extension' => '0300',
                'target_kind' => PhoneExtension::TARGET_RING_GROUP,
                'target_id' => $group->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.target_name', 'Appointments');
    }

    /**
     * The leading zero is required here too.
     *
     * A second write path is a second chance to let a rule go. The rule lives
     * on the model and both readers use it — this is the case that says the
     * new door did not skip it.
     */
    public function test_the_leading_zero_is_required_on_this_door_too(): void
    {
        [$t, $connection, $editor, , $group] = $this->account();

        $this->signedInAs($editor)
            ->postJson('/api/v1/phone/extensions/directory', [
                'connection_id' => $connection->id,
                'extension' => '300',
                'target_kind' => PhoneExtension::TARGET_RING_GROUP,
                'target_id' => $group->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');
    }

    /**
     * And so is the one that makes "call 0101" mean one thing.
     *
     * Shared with the Users tab's door, and the refusal NAMES who holds it —
     * the point is that somebody can go and find them, not merely that the
     * save failed.
     */
    public function test_digits_already_in_use_are_refused_by_name(): void
    {
        [$t, $connection, $editor, $alice, $group] = $this->account();

        $this->inTenant($t['org'], fn () => PhoneExtension::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'user_id' => $alice->id,
            'extension' => '0101',
        ]));

        $this->signedInAs($editor)
            ->postJson('/api/v1/phone/extensions/directory', [
                'connection_id' => $connection->id,
                'extension' => '0101',
                'target_kind' => PhoneExtension::TARGET_RING_GROUP,
                'target_id' => $group->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.extension.0', 'Alice Nolan already has extension 0101.');
    }

    /**
     * A team on another phone account cannot be given digits here.
     *
     * It would expand to Client identities this line can never reach, so the
     * extension would ring nobody and look perfectly configured — the failure
     * this module keeps paying for.
     */
    public function test_a_team_from_another_account_is_refused(): void
    {
        [$t, $connection, $editor] = $this->account();

        $foreign = $this->inTenant($t['org'], function () use ($t) {
            // A COMPANY scope, because one organization may hold only one
            // organization-scoped connection — a second would collide on the
            // unique index rather than model a second phone account.
            $other = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
            ]);

            return PhoneRingGroup::query()->create([
                'connection_id' => $other->id,
                'name' => 'Warehouse',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);
        });

        $this->signedInAs($editor)
            ->postJson('/api/v1/phone/extensions/directory', [
                'connection_id' => $connection->id,
                'extension' => '0300',
                'target_kind' => PhoneExtension::TARGET_RING_GROUP,
                'target_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_id');
    }

    public function test_an_extension_can_be_retired(): void
    {
        [$t, $connection, $editor, $alice] = $this->account();

        $row = $this->inTenant($t['org'], fn () => PhoneExtension::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'user_id' => $alice->id,
            'extension' => '0101',
        ]));

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/extensions/'.$row->id)
            ->assertNoContent();

        $this->inTenant($t['org'], fn () => $this->assertNull(
            PhoneExtension::query()->find($row->id),
        ));
    }

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: User, 3: User, 4: PhoneRingGroup} */
    private function account(): array
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view', 'phone.edit']);
        $alice = $this->userWithRole($t, 'Alice Nolan', ['phone.view']);

        [$connection, $group] = $this->inTenant($t['org'], function () use ($t, $alice) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Appointments',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$alice->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            return [$connection, $group];
        });

        return [$t, $connection, $editor, $alice, $group];
    }
}
