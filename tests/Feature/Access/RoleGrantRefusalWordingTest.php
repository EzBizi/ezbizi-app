<?php

namespace Tests\Feature\Access;

use App\Models\InspectionDefinition;
use App\Models\Mailbox;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Support\Access\InboxAccess;
use App\Support\Inspection\InspectionGrantAccess;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The refusals the roles editor shows when inboxes, named inspections or
 * phone lines cannot be granted — pinned word for word, because the three
 * classes share one implementation (RoleGrantAccess) and only their sentences
 * tell them apart.
 */
class RoleGrantRefusalWordingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Nothing was refused.');
    }

    public function test_inbox_refusals(): void
    {
        $t = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($t['org'], fn () => array_map(fn (string $a) => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type' => Mailbox::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'address' => $a,
            'display_name' => $a,
            'provider' => 'imap',
        ])->id, ['f@a.test', 'b@a.test', 'c@a.test', 'd@a.test', 'e@a.test', 'a@a.test', 'g@a.test']));
        $actor = $this->userWithRole($t, 'Clerk', ['email.view']);
        $access = app(InboxAccess::class);

        $this->inTenant($t['org'], function () use ($access, $actor, $ids, $t) {
            $this->assertSame(['inboxes' => ['1 of the inboxes chosen is no longer connected to this organization. '
                .'Reload the roles screen — somebody disconnected mail while this was open, '
                .'and it cannot be granted to anybody until it is connected again.']],
                $this->refusal(fn () => $access->assertGrantable($actor, ['nope'])));
            $this->assertSame(['inboxes' => ['2 of the inboxes chosen are no longer connected to this organization. '
                .'Reload the roles screen — somebody disconnected mail while this was open, '
                .'and it cannot be granted to anybody until it is connected again.']],
                $this->refusal(fn () => $access->assertGrantable($actor, ['nope', 'nada', 'nope'])));
            $this->assertSame(['inboxes' => ['You cannot grant f@a.test, because you cannot open it yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, [$ids[0]])));
            $this->assertSame(['inboxes' => ['You cannot grant a@a.test, b@a.test, c@a.test, d@a.test, e@a.test and 2 more, '
                .'because you cannot open them yourself. Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, $ids)));

            $blind = Role::create(['organization_id' => $t['org']->id, 'name' => 'Blind', 'is_system' => false]);
            $this->assertSame(['inboxes' => ['This role cannot open Email, so an inbox granted to it would open nothing. '
                .'Tick Email itself first, then choose which inboxes it may read.']],
                $this->refusal(fn () => $access->assertReachable($blind, [$ids[0]])));

        });
    }

    public function test_named_inspection_refusals(): void
    {
        $t = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($t['org'], fn () => array_map(fn (string $n) => InspectionDefinition::create([
            'name' => $n,
            'created_by' => $t['admin']->id,
        ])->id, ['Level 2', 'Level 1', 'Chimney', 'Dryer']));
        $actor = $this->userWithRole($t, 'Clerk', ['inspection.view']);
        $access = app(InspectionGrantAccess::class);

        $this->inTenant($t['org'], function () use ($access, $actor, $ids, $t) {
            $this->assertSame(['inspections' => ['1 of the inspections chosen is no longer in this organization. '
                .'Reload the roles screen — somebody removed one while this was open.']],
                $this->refusal(fn () => $access->assertGrantable($actor, ['nope'])));
            $this->assertSame(['inspections' => ['2 of the inspections chosen are no longer in this organization. '
                .'Reload the roles screen — somebody removed one while this was open.']],
                $this->refusal(fn () => $access->assertGrantable($actor, ['nope', 'nada'])));
            $this->assertSame(['inspections' => ['You cannot grant Level 2, because you cannot offer it yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, [$ids[0]])));
            $this->assertSame(['inspections' => ['You cannot grant Level 1 and Level 2, because you cannot offer them yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, [$ids[0], $ids[1]])));
            $this->assertSame(['inspections' => ['You cannot grant Chimney, Dryer and 2 more, because you cannot offer them yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, $ids)));

            $blind = Role::create(['organization_id' => $t['org']->id, 'name' => 'Blind', 'is_system' => false]);
            $this->assertSame(['inspections' => ['This role cannot open Inspection, so a named inspection granted to it would open nothing. '
                .'Tick Inspection itself first, then choose which inspections it may start.']],
                $this->refusal(fn () => $access->assertReachable($blind, [$ids[0]])));
        });
    }

    public function test_phone_line_refusals(): void
    {
        $t = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return array_map(fn (array $n) => PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => $n[0],
                'label' => $n[1],
            ])->id, [['+13055550101', 'Main'], ['+13055550102', ''], ['+13055550103', 'Spare']]);
        });
        // Every active person holds every line now, so only someone inactive holds none.
        $actor = $this->userWithRole($t, 'Gone', ['phone.view']);
        $this->inTenant($t['org'], fn () => $actor->forceFill(['is_active' => false])->save());
        $access = app(PhoneNumberAccess::class);

        $this->inTenant($t['org'], function () use ($access, $actor, $ids, $t) {
            $this->assertSame(['phone_numbers' => ['1 of the phone numbers chosen is no longer in this organization. '
                .'Reload the roles screen — somebody released one while this was open.']],
                $this->refusal(fn () => $access->assertGrantable($actor, ['nope'])));
            $this->assertSame(['phone_numbers' => ['You cannot grant +13055550102, because you cannot open it yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, [$ids[1]])));
            $this->assertSame(['phone_numbers' => ['You cannot grant +13055550102, Main and 1 more, because you cannot open them yourself. '
                .'Nobody can hand out more than they have.']],
                $this->refusal(fn () => $access->assertGrantable($actor, $ids)));

            $blind = Role::create(['organization_id' => $t['org']->id, 'name' => 'Blind', 'is_system' => false]);
            $this->assertSame(['phone_numbers' => ['This role cannot open Communication, so a phone number granted to it would open nothing. '
                .'Tick Communication itself first, then choose which numbers it may use.']],
                $this->refusal(fn () => $access->assertReachable($blind, [$ids[0]])));

            $this->assertSame(['+13055550102', 'Main', 'Spare'], $access->labelsHeldBy($t['admin']->role));
        });
    }
}
