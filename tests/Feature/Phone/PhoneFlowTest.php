<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use App\Support\Phone\TwimlRenderer;

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

    /**
     * A menu can be pointed at a line.
     *
     * ── The gap this closes ──────────────────────────────────────
     *
     * `flow_id` was fillable on the model and read by the renderer, and NO
     * endpoint in the module ever wrote it. The only writes anywhere were
     * FlowsController clearing it when a flow was deleted. So a menu could be
     * built, named, given digits and destinations — and never attached to
     * anything. The column was permanently null, every caller skipped straight
     * past the menu, and the whole IVR feature was decoration.
     *
     * It is the same family as the ring groups and voicemail_transcribe: it
     * saved, it displayed, it did nothing. This is the write that makes the
     * feature exist.
     */
    public function test_a_line_can_be_pointed_at_a_menu_and_refuses_a_foreign_one(): void
    {
        [$t, $connection, $group] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        [$number, $flow, $foreign] = $this->inTenant($t['org'], function () use ($t, $connection, $group) {
            $flow = PhoneFlow::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press one for sales.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ],
                ],
            ]);

            // A second connection, so "belongs to another account" is a real
            // case rather than a hypothetical one.
            $other = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACotherotherotherotherotherot',
                'api_key_sid' => 'SKotherotherotherotherotherot',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $other->writeSecrets('secret', 'token');
            $other->save();

            $foreign = PhoneFlow::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $other->id,
                'name' => 'Somebody else',
                'nodes' => ['type' => 'menu', 'prompt' => 'No.', 'options' => []],
            ]);

            $number = PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'twilio_sid' => 'PN'.str_repeat('f', 32),
                'e164' => '+15615559001',
                'label' => 'Main',
                'after_hours' => 'voicemail',
            ]);

            return [$number, $flow, $foreign];
        });

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, ['flow_id' => $flow->id])
            ->assertOk()
            ->assertJsonPath('data.flow_id', $flow->id);

        // And the caller actually hears it — the point of the whole exercise.
        $this->inTenant($t['org'], function () use ($number, $flow) {
            $this->assertSame($flow->id, $number->fresh()->flow_id);
            $xml = (new \App\Support\Phone\TwimlRenderer)->inbound($number->fresh(), []);
            $this->assertStringContainsString('Press one for sales.', $xml);
            $this->assertStringContainsString('<Gather', $xml);
        });

        // A menu on another Twilio account names groups this line cannot
        // reach, so it would read its prompt and dial nobody.
        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, ['flow_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('flow_id');
    }

    /**
     * A menu that is fully configured and never plays.
     *
     * This is the module's signature failure wearing another hat: the flow
     * saves, the pane lists it, it is attached to a line, and no caller ever
     * hears it — because the renderer looks for a `menu` node and this one has
     * none. Nobody reports it, either. The caller does not know they missed a
     * menu, and the owner does not know theirs is skipped.
     *
     * Two readers are asserted together on purpose. The list has to say the
     * menu never plays, AND the line's own trace has to mark the step skipped,
     * because General draws that step and Call flow reports it. One without
     * the other is a screen that disagrees with the call.
     */
    public function test_a_menu_with_no_playable_step_says_so_and_is_marked_skipped(): void
    {
        [$t, $connection, $group] = $this->connectedCompany();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $number = $this->inTenant($t['org'], function () use ($t, $connection, $group) {
            // Everything a working menu has, except the node the renderer
            // actually looks for.
            $broken = PhoneFlow::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Never heard',
                'nodes' => [
                    'type' => 'something_else',
                    'prompt' => 'Press one for sales.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ],
                ],
            ]);

            return PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035557788',
                'label' => 'Front',
                'twilio_sid' => 'PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'flow_id' => $broken->id,
                'ring_group_id' => $group->id,
                'after_hours' => 'voicemail',
                'record' => false,
                'transcribe' => false,
            ]);
        });

        $listed = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/flows')
            ->assertOk()
            ->json('data');

        $this->assertFalse(
            collect($listed)->firstWhere('name', 'Never heard')['plays'],
            'a menu with no playable step was listed as though callers hear it',
        );

        $steps = $this->inTenant(
            $t['org'],
            fn () => (new TwimlRenderer)->describe($number->refresh()),
        );

        $menu = collect($steps)->firstWhere('step', 'menu');

        $this->assertTrue($menu['skipped'], 'the trace did not mark the menu skipped');

        // And `usable` is NOT the flag. It means "does not break the call" on
        // this step, which stays true — folding the two together is how the
        // one step the marker exists for would render as fine.
        $this->assertTrue($menu['usable']);
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
