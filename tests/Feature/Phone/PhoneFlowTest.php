<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Call menus: what a caller hears, and where each key sends them (spec §7).
 */
class PhoneFlowTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneRingGroup} */
    private function connectedCompany(): array
    {
        $t = $this->provisionTenant();

        [$connection, $group] = $this->inTenant($t['org'], function () use ($t) {
            $c = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACflowflowflowflowflowflowflowf',
                'api_key_sid' => 'SKflowflowflowflowflowflowflowf',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $c->writeSecrets('secret', 'token');
            $c->save();

            $g = PhoneRingGroup::query()->create([
                'connection_id' => $c->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'overflow' => 'voicemail',
                'member_user_ids' => [],
                'member_role_ids' => [],
            ]);

            return [$c, $g];
        });

        return [$t, $connection, $group];
    }

    public function test_a_menu_is_stored_in_the_shape_the_renderer_reads(): void
    {
        [$t, $connection, $group] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/flows', [
            'connection_id' => $connection->id,
            'name' => 'Main menu',
            'prompt' => 'Press 1 for the desk, 2 to leave a message.',
            'options' => [
                ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                ['digit' => '2', 'action' => 'voicemail'],
            ],
        ])->assertCreated();

        $flow = $this->inTenant($t['org'], fn () => PhoneFlow::query()->first());

        // TwimlRenderer::findMenu looks for type=menu and reads options[].digit.
        // A shape it does not recognise is a menu that silently never routes.
        $this->assertSame('menu', $flow->nodes['type']);
        $this->assertSame('1', $flow->nodes['options'][0]['digit']);
        $this->assertSame('ring_group', $flow->nodes['options'][0]['action']);
        $this->assertSame($group->id, $flow->nodes['options'][0]['ring_group_id']);
        $this->assertSame('voicemail', $flow->nodes['options'][1]['action']);
    }

    public function test_two_options_cannot_share_a_key(): void
    {
        [$t, $connection, $group] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/flows', [
            'connection_id' => $connection->id,
            'name' => 'Main menu',
            'prompt' => 'Press 1.',
            'options' => [
                ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                ['digit' => '1', 'action' => 'voicemail'],
            ],
        ])->assertStatus(422);
    }

    /**
     * A group on another account expands to Client identities this call can
     * never reach, so the Dial would ring nobody and look entirely correct.
     */
    public function test_a_ring_group_from_another_phone_account_is_refused(): void
    {
        [$t, $connection, ] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $stranger = $this->inTenant($t['org'], function () use ($t) {
            $other = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACotherotherotherotherotherothe',
                'api_key_sid' => 'SKotherotherotherotherotherothe',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $other->writeSecrets('s', 't');
            $other->save();

            return PhoneRingGroup::query()->create([
                'connection_id' => $other->id,
                'name' => 'Someone else',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'overflow' => 'voicemail',
                'member_user_ids' => [],
                'member_role_ids' => [],
            ]);
        });

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/flows', [
            'connection_id' => $connection->id,
            'name' => 'Main menu',
            'prompt' => 'Press 1.',
            'options' => [
                ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $stranger->id],
            ],
        ])->assertStatus(422);

        // The key is options.0.ring_group_id; assert against the whole error
        // bag so a change in how Laravel nests it does not read as a pass.
        $this->assertStringContainsString(
            'different phone account',
            json_encode($response->json('errors')),
        );
    }

    public function test_deleting_a_menu_clears_the_lines_that_played_it(): void
    {
        [$t, $connection, $group] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $created = $this->signedInAs($editor)->postJson('/api/v1/phone/flows', [
            'connection_id' => $connection->id,
            'name' => 'Main menu',
            'prompt' => 'Press 1.',
            'options' => [
                ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
            ],
        ])->assertCreated();

        $flowId = $created->json('data.id');

        $number = $this->inTenant($t['org'], function () use ($t, $connection, $flowId) {
            return PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'twilio_sid' => 'PNflowflowflowflowflowflowflowf',
                'e164' => '+12035550111',
                'label' => 'Main line',
                'after_hours' => 'voicemail',
                'flow_id' => $flowId,
            ]);
        });

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/flows/'.$flowId)
            ->assertOk();

        // A line pointing at a menu that no longer exists would gather against
        // nothing on the next call.
        $this->assertNull(
            $this->inTenant($t['org'], fn () => PhoneNumber::query()->find($number->id)->flow_id),
        );
    }
}
