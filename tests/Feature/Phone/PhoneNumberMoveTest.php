<?php

namespace Tests\Feature\Phone;

use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneRingGroup;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Handing a line to another company.
 *
 * The interesting cases are not the happy one. They are what a moved number
 * is left POINTING AT, and whether the carrier agrees the move happened.
 */
class PhoneNumberMoveTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: Company} */
    private function orgAccountAndSecondCompany(): array
    {
        $t = $this->provisionTenant();

        [$connection, $other] = $this->inTenant($t['org'], function () use ($t) {
            $c = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $other = Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Everdoors Co',
            ]);

            return [$c, $other];
        });

        return [$t, $connection, $other];
    }

    private function line(array $t, PhoneConnection $connection, string $sid): PhoneNumber
    {
        return $this->inTenant($t['org'], fn () => PhoneNumber::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_COMPANY,
            'scope_id' => $t['company']->id,
            'twilio_sid' => $sid,
            'e164' => '+15615559200',
            'label' => 'Main',
            'after_hours' => 'voicemail',
        ]));
    }

    private function group(array $t, PhoneConnection $connection, string $name): PhoneRingGroup
    {
        return $this->inTenant($t['org'], fn () => PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'name' => $name,
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => [],
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]));
    }

    /**
     * Within ONE Twilio account, a move is a rescope and nothing else.
     *
     * This is the common shape: a business with a single organization-level
     * Twilio account has every company resolving to it, so nothing needs to
     * happen at the carrier — and clearing the ring group here would destroy
     * working setup for no reason at all.
     */
    public function test_moving_inside_one_account_rescopes_without_touching_twilio(): void
    {
        [$t, $connection, $other] = $this->orgAccountAndSecondCompany();
        $admin = $this->userWithRole($t, 'Phone admin', ['phone.view', 'phone.edit']);
        $number = $this->line($t, $connection, 'PN'.str_repeat('m', 32));
        $group = $this->group($t, $connection, 'Front desk');

        $this->inTenant($t['org'], fn () => $number->forceFill([
            'ring_group_id' => $group->id,
        ])->save());

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($admin)
            ->postJson('/api/v1/phone/numbers/'.$number->id.'/move', [
                'scope_type' => 'company',
                'scope_id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.scope_id', $other->id);

        $this->assertSame(
            0,
            collect($gateway->calls)->where('method', 'moveIncomingToAccount')->count(),
            'the carrier was called for a move that never left its account',
        );

        $this->inTenant($t['org'], function () use ($number, $group) {
            $this->assertSame($group->id, $number->fresh()->ring_group_id);
        });
    }

    /**
     * ACROSS accounts, everything the line points at must be let go.
     *
     * Ring groups, hours, menus and voicemail boxes belong to a company now.
     * Carrying them over would leave the new owner's line ringing the old
     * owner's staff and reading the old owner's greeting — reintroducing, one
     * line at a time, the leak that scoping them was meant to close.
     */
    public function test_moving_across_accounts_lets_go_of_the_old_company_settings(): void
    {
        [$t, $connection, $other] = $this->orgAccountAndSecondCompany();
        $admin = $this->userWithRole($t, 'Phone admin', ['phone.view', 'phone.edit']);
        $number = $this->line($t, $connection, 'PN'.str_repeat('n', 32));
        $group = $this->group($t, $connection, 'A-1 Front desk');

        // The destination holds a Twilio account of its own, so the number
        // genuinely has to change hands at the carrier.
        $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $other->id,
        ]));

        $sourceConnectionId = $number->connection_id;

        $this->inTenant($t['org'], function () use ($number, $group, $admin) {
            $number->forceFill(['ring_group_id' => $group->id])->save();

            PhoneNumberGrant::query()->create([
                'organization_id' => $number->organization_id,
                'number_id' => $number->id,
                'role_id' => $admin->role_id,
            ]);
        });

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $this->signedInAs($admin)
            ->postJson('/api/v1/phone/numbers/'.$number->id.'/move', [
                'scope_type' => 'company',
                'scope_id' => $other->id,
            ])
            ->assertOk();

        $moved = collect($gateway->calls)->firstWhere('method', 'moveIncomingToAccount');
        $this->assertNotNull($moved, 'the carrier was never told the number changed hands');

        // The webhooks must move WITH it. Our URLs carry the connection in
        // their path, so a moved line left on the old URL keeps being answered
        // by the old company's ring groups and hours.
        $params = $moved['args'][3];
        $this->assertStringContainsString('/voice/', $params['voiceUrl']);
        $this->assertStringNotContainsString(
            '/'.$sourceConnectionId,
            $params['voiceUrl'],
            'the moved line still points at the old company webhook',
        );

        $this->inTenant($t['org'], function () use ($number) {
            $fresh = $number->fresh();

            $this->assertNull($fresh->ring_group_id, 'the line still rings the old company');
            $this->assertNull($fresh->hours_schedule_id);
            $this->assertNull($fresh->flow_id);
            $this->assertNull($fresh->voicemail_box_id);

            $this->assertSame(
                0,
                PhoneNumberGrant::query()->where('number_id', $fresh->id)->count(),
                'consent to read this line survived it changing owner',
            );
        });
    }

    /**
     * A carrier refusal must leave nothing behind.
     *
     * A row saying the number moved while Twilio still routes it to the old
     * company is worse than a refusal, because the screen would then stop
     * anybody looking for the problem.
     */
    public function test_a_refused_carrier_move_writes_nothing(): void
    {
        [$t, $connection, $other] = $this->orgAccountAndSecondCompany();
        $admin = $this->userWithRole($t, 'Phone admin', ['phone.view', 'phone.edit']);
        $number = $this->line($t, $connection, 'PN'.str_repeat('p', 32));

        $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $other->id,
        ]));

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failMoveIncomingWith = 'The number is in the middle of a port.';

        $this->signedInAs($admin)
            ->postJson('/api/v1/phone/numbers/'.$number->id.'/move', [
                'scope_type' => 'company',
                'scope_id' => $other->id,
            ])
            ->assertStatus(422);

        $this->inTenant($t['org'], function () use ($number, $t) {
            $this->assertSame($t['company']->id, $number->fresh()->scope_id);
        });
    }
}
