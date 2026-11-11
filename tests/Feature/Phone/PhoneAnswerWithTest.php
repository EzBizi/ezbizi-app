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
 * What a line answers with, when somebody has said.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"On Settings - Numbers on the Who it Rings, i want more options
 * besides ring groups, i should be able to choose IVR Menus, Ring Groups,
 * Voicemail, User Specific, Self."*
 *
 * ── Why every case here sets a ring group as well ────────────────
 *
 * Because that is what makes the assertions mean anything. A line with no
 * group reaches voicemail whatever it is set to, so a test that left the group
 * out would pass on a build that ignored `answer_with` entirely. Each case
 * below has a live, available group that the OLD code would have rung.
 */
class PhoneAnswerWithTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Voicemail ON PURPOSE, on a line that has somebody to ring.
     *
     * Until there was a word for this, "send callers to voicemail" and
     * "nothing is configured" were the same state — which is why the call-flow
     * chart draws an unset line as a dead end. The group here is available, so
     * a build ignoring the setting rings it and this fails.
     */
    public function test_voicemail_is_chosen_over_an_available_group(): void
    {
        $xml = $this->render(['answer_with' => PhoneNumber::ANSWER_VOICEMAIL]);

        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    /**
     * Choosing the group STOPS the menu playing.
     *
     * A flow used to win by order: if `flow_id` was set the menu played,
     * whatever else the line said. So this is the case that decides whether
     * the picker means anything on a line that already has a menu attached —
     * and a picker that silently changes nothing is worse than no picker.
     */
    public function test_choosing_the_group_silences_an_attached_menu(): void
    {
        $xml = $this->render(
            ['answer_with' => PhoneNumber::ANSWER_RING_GROUP],
            withMenu: true,
        );

        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('<Client', $xml);
    }

    /**
     * The control for the case above: NULL still plays the menu.
     *
     * Without this, a build that never plays a menu at all passes the
     * assertion above — and every line in production is NULL today, so this is
     * the behaviour that must not move.
     */
    public function test_a_line_that_has_chosen_nothing_still_plays_its_menu(): void
    {
        $xml = $this->render([], withMenu: true);

        $this->assertStringContainsString('<Gather', $xml);
    }

    /**
     * One named person, and NOT the rest of the group.
     *
     * The group holds two people and both are available, so a build falling
     * through to the group rings two clients and this fails on the count as
     * well as on the name.
     */
    public function test_a_named_person_is_rung_and_nobody_else(): void
    {
        [$xml, $me, $mate] = $this->renderWith(
            fn (array $t, PhoneConnection $c, User $me) => [
                'answer_with' => PhoneNumber::ANSWER_USER,
                'answer_user_id' => $me->id,
            ],
        );

        $this->assertStringContainsString(ClientIdentity::of($me, 'desk'), $xml);
        $this->assertStringNotContainsString(ClientIdentity::of($mate, 'mate-desk'), $xml);
        $this->assertSame(1, substr_count($xml, '<Client'));
    }

    /**
     * `self` rings whoever the line belongs to, which is its extension holder.
     *
     * No column for it on purpose: an extension pointing at a number is
     * already how the Dial pad decides which line is yours, so a second record
     * of the same fact could only drift from it.
     */
    public function test_self_rings_the_person_whose_extension_names_the_line(): void
    {
        [$xml, $me, $mate] = $this->renderWith(
            fn (array $t, PhoneConnection $c, User $me) => [
                'answer_with' => PhoneNumber::ANSWER_SELF,
            ],
            extensionFor: 'me',
        );

        $this->assertStringContainsString(ClientIdentity::of($me, 'desk'), $xml);
        $this->assertStringNotContainsString(ClientIdentity::of($mate, 'mate-desk'), $xml);
    }

    /**
     * `self` on a line nobody holds an extension for reaches voicemail.
     *
     * A real state rather than an error — somebody sets a line to ring its
     * owner before assigning one — and the honest end is the same one a group
     * with nobody available gives. Asserted beside the case above, which is
     * what makes it distinguishable from a build where `self` never rings
     * anybody.
     */
    public function test_self_with_no_extension_reaches_voicemail(): void
    {
        [$xml] = $this->renderWith(
            fn (array $t, PhoneConnection $c, User $me) => [
                'answer_with' => PhoneNumber::ANSWER_SELF,
            ],
        );

        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
    }

    /**
     * A named person who is away reaches voicemail rather than silence.
     *
     * Do Not Disturb is about the PERSON, not about the route, so their own
     * line does not ring them either.
     */
    public function test_a_named_person_on_do_not_disturb_reaches_voicemail(): void
    {
        [$xml, $me] = $this->renderWith(
            fn (array $t, PhoneConnection $c, User $me) => [
                'answer_with' => PhoneNumber::ANSWER_USER,
                'answer_user_id' => $me->id,
            ],
            away: true,
        );

        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
    }

    /**
     * THE CHART SAYS THE SAME THING THE PHONE DOES.
     *
     * `graph()` exists to be the one honest answer to "what does this line
     * do", which it can only be while it branches on the same facts
     * `inbound()` branches on. A line set to ring one person would otherwise
     * still be DRAWN ringing its whole team — and this chart is what Jose
     * reads to find out what a line does, so a chart that lags the renderer is
     * worse than no chart.
     */
    public function test_the_chart_draws_the_one_person_rather_than_the_team(): void
    {
        [$graph, $me] = $this->graphWith(
            fn (array $t, PhoneConnection $c, User $me) => [
                'answer_with' => PhoneNumber::ANSWER_USER,
                'answer_user_id' => $me->id,
            ],
        );

        $kinds = array_column($graph['nodes'], 'kind');

        $this->assertContains('person', $kinds);
        $this->assertNotContains(
            'ring_group',
            $kinds,
            'the chart drew the team on a line that rings one person',
        );

        $titles = array_column($graph['nodes'], 'title');
        $this->assertContains('Ring '.$me->name, $titles);
    }

    /**
     * A menu the line has stopped playing is drawn as SKIPPED, not dropped.
     *
     * I wrote this test expecting the menu node to disappear, and the code did
     * something better — so the assertion changed rather than the code. A flow
     * is still attached; the line simply no longer plays it. That is precisely
     * what `skipped` means in this graph: configured, on the walk, and doing
     * nothing. Dropping the box would hide the fact that a menu somebody built
     * is now dead, which is the failure the state exists to surface.
     *
     * The distinguishing half is the STATE, not the presence. A chart reading
     * `flow_id` alone draws the same box as `ok` and tells you the opposite.
     */
    public function test_a_menu_the_line_no_longer_plays_is_drawn_as_skipped(): void
    {
        [$graph] = $this->graphWith(
            fn () => ['answer_with' => PhoneNumber::ANSWER_RING_GROUP],
            withMenu: true,
        );

        $menu = null;
        foreach ($graph['nodes'] as $node) {
            if ($node['kind'] === 'menu') {
                $menu = $node;
            }
        }

        $this->assertNotNull($menu, 'the attached menu vanished from the chart');
        $this->assertSame(
            'skipped',
            $menu['state'],
            'the chart drew a live menu on a line that no longer plays one',
        );

        $this->assertContains('ring_group', array_column($graph['nodes'], 'kind'));
    }

    /**
     * The control: with nothing chosen, the menu is still drawn.
     *
     * Every production line is null today, so this is the behaviour that must
     * not move — and without it the assertion above passes on a chart that
     * never draws a menu at all.
     */
    public function test_the_chart_still_draws_a_menu_when_nothing_is_chosen(): void
    {
        [$graph] = $this->graphWith(fn () => [], withMenu: true);

        $this->assertContains('menu', array_column($graph['nodes'], 'kind'));
    }

    /**
     * A destination that names nothing is refused while somebody is looking.
     *
     * The alternative is a save that succeeds, a screen that shows the setting
     * as chosen, and a failure at the only moment that matters — a real caller
     * on the line, reaching voicemail for a reason nobody can see.
     */
    public function test_ringing_one_person_without_naming_them_is_refused(): void
    {
        [$number, $editor] = $this->lineToEdit();

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, [
                'answer_with' => PhoneNumber::ANSWER_USER,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.answer_user_id.0', 'Choose the person this line should ring.');
    }

    /** Jose, 2026-10-16: a line marked as a lead line feeds EZ Calls / EZ Texts. Off by default. */
    public function test_a_line_can_be_marked_a_lead_line_and_starts_off(): void
    {
        [$number, $editor] = $this->lineToEdit();
        $this->assertFalse((bool) $number->fresh()->lead_line);

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, ['lead_line' => true])
            ->assertOk()
            ->assertJsonPath('data.lead_line', true);
    }

    public function test_a_menu_hang_up_is_inbound_unanswered_no_key_no_voicemail(): void
    {
        $call = (new \App\Models\PhoneCall)->forceFill(['direction' => 'inbound', 'voicemail' => false]);
        $this->assertTrue($call->isMenuHangUp());

        foreach ([['menu_key' => '1'], ['voicemail' => true], ['direction' => 'outbound']] as $differs) {
            $this->assertFalse((new \App\Models\PhoneCall)->forceFill($differs + ['direction' => 'inbound', 'voicemail' => false])->isMenuHangUp());
        }
    }

    /**
     * And a choice that names something is saved and read back.
     *
     * The positive control for the refusal above — without it, a build that
     * refused every `answer_with` would pass that test and no other.
     */
    public function test_choosing_voicemail_saves_and_comes_back(): void
    {
        [$number, $editor] = $this->lineToEdit();

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, [
                'answer_with' => PhoneNumber::ANSWER_VOICEMAIL,
            ])
            ->assertOk()
            ->assertJsonPath('data.answer_with', PhoneNumber::ANSWER_VOICEMAIL);
    }

    /** Jose, 2026-11-11: an overridden line forwards, past the menu it would otherwise play. */
    public function test_an_override_forwards_past_the_menu(): void
    {
        $xml = $this->render(['override_on' => true, 'override' => ['action' => 'forward', 'number' => '+13055550111']], withMenu: true);

        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringContainsString('<Number>+13055550111</Number>', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
    }

    /** The call-flow chart says the line is overridden, so nobody wonders where the menu went. */
    public function test_the_chart_shows_an_overridden_line(): void
    {
        [$graph] = $this->graphWith(fn () => ['override_on' => true, 'override' => ['action' => 'forward', 'number' => '+13055550111']], withMenu: true);

        $this->assertContains('override', array_column($graph['nodes'], 'kind'));
        $this->assertNotContains('menu', array_column($graph['nodes'], 'kind'));
    }

    /** Switched off, the regular flow runs: the override is kept but ignored. */
    public function test_an_override_switched_off_leaves_the_flow_alone(): void
    {
        $xml = $this->render(['override_on' => false, 'override' => ['action' => 'forward', 'number' => '+13055550111']], withMenu: true);

        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringNotContainsString('+13055550111', $xml);
    }

    public function test_the_override_is_validated_and_audited(): void
    {
        [$number, $editor] = $this->lineToEdit();

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, ['override_on' => true, 'override' => ['action' => 'forward']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('override.number');

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/numbers/'.$number->id, ['override_on' => true, 'override' => ['action' => 'forward', 'number' => '+13055550111']])
            ->assertOk()
            ->assertJsonPath('data.override_on', true)
            ->assertJsonPath('data.override.number', '+13055550111');

        $this->inTenant($number->organization, fn () => $this->assertTrue(
            \App\Models\AuditEvent::query()->where('action', 'phone.number.override')->exists(),
        ));
    }

    /** @return array{0: PhoneNumber, 1: User} */
    private function lineToEdit(): array
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view', 'phone.edit']);

        $number = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13057249402',
            ]);
        });

        return [$number, $editor];
    }

    /**
     * The same fixture as [renderWith], drawn instead of rendered.
     *
     * @return array{0: array<string, mixed>, 1: User, 2: User}
     */
    private function graphWith(callable $settings, bool $withMenu = false): array
    {
        return $this->buildLine($settings, $withMenu, false, null, graph: true);
    }

    /** @param array<string, mixed> $settings */
    private function render(array $settings, bool $withMenu = false): string
    {
        [$xml] = $this->renderWith(fn () => $settings, withMenu: $withMenu);

        return $xml;
    }

    /**
     * Build the line, apply the settings the closure returns, render inbound.
     *
     * @return array{0: string, 1: User, 2: User}
     */
    private function renderWith(
        callable $settings,
        bool $withMenu = false,
        bool $away = false,
        ?string $extensionFor = null,
    ): array {
        return $this->buildLine($settings, $withMenu, $away, $extensionFor, graph: false);
    }

    /**
     * @return array{0: mixed, 1: User, 2: User}
     */
    private function buildLine(
        callable $settings,
        bool $withMenu,
        bool $away,
        ?string $extensionFor,
        bool $graph,
    ): array {
        $t = $this->provisionTenant();
        $me = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);
        $mate = $this->userWithRole($t, 'Bar Ohayon', ['phone.view']);

        $out = $this->inTenant($t['org'], function () use (
            $t, $me, $mate, $settings, $withMenu, $away, $extensionFor, $graph
        ) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ([[$me, 'desk'], [$mate, 'mate-desk']] as [$person, $device]) {
                PhonePresence::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'state' => $away && $person->is($me)
                        ? PhonePresence::STATE_DND
                        : PhonePresence::STATE_REGISTERED,
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

            // BOTH of them, available, so every assertion above is about the
            // setting rather than about an empty group.
            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$me->id, $mate->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13057249401',
                'ring_group_id' => $group->id,
            ]);

            if ($withMenu) {
                $flow = PhoneFlow::query()->create([
                    'connection_id' => $connection->id,
                    'name' => 'Main menu',
                    'nodes' => [
                        'type' => 'menu',
                        'prompt' => 'Press 1 for the desk.',
                        'options' => [
                            ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ],
                    ],
                ]);

                $number->forceFill(['flow_id' => $flow->id])->save();
            }

            if ($extensionFor === 'me') {
                PhoneExtension::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $me->id,
                    'number_id' => $number->id,
                    'extension' => '0204',
                ]);
            }

            $chosen = $settings($t, $connection, $me);
            if ($chosen !== []) {
                $number->forceFill($chosen)->save();
            }

            return $graph
                ? (new TwimlRenderer)->graph($number->fresh())
                : (new TwimlRenderer)->inbound($number->fresh());
        });

        return [$out, $me, $mate];
    }
}
