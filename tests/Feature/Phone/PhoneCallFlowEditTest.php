<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Every step on the Call flow screen knows where it is changed.
 *
 * Jose, 2026-09-23: *"i'd like to be able to click the 4 items they each have,
 * and that should direct me to the screen where that can be changed."*
 *
 * ── Why this is asserted against the CHART as well ───────────────
 *
 * `describe()` and `graph()` are two drawings of one call flow. They already
 * branch on the same predicates, which is what keeps them from disagreeing
 * about what a line does; the `{pane, id}` stamps are the same claim about
 * where it is changed, and nothing but a test stops them drifting.
 */
class PhoneCallFlowEditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Every step names a pane, and names the right one.
     *
     * Read off the fully configured line, so each arm is the interesting one:
     * a menu that exists goes to the menu, a group that exists goes to the
     * group. The unconfigured cases are the test below.
     */
    public function test_each_step_names_the_section_that_changes_it(): void
    {
        [$t, $number, $flow, $group] = $this->line();

        $steps = $this->describe($t, $number);

        $this->assertSame(
            ['pane' => 'numbers', 'id' => (string) $number->id],
            $steps['arrival']['edit'],
        );
        $this->assertSame(['pane' => 'flows', 'id' => (string) $flow->id], $steps['menu']['edit']);
        $this->assertSame(['pane' => 'ring-groups', 'id' => (string) $group->id], $steps['answer']['edit']);
        $this->assertSame('hours', $steps['hours']['edit']['pane']);
    }

    /**
     * WITH NOTHING SET UP, the steps point at the LINE.
     *
     * The case that decides whether the feature helps. "Nobody answers this
     * line" is fixed by pointing the LINE at a team — sending somebody to Ring
     * groups would show them a list of teams that are all perfectly fine and
     * no way to say which one answers here. Same for a line with no menu.
     *
     * And it is the case that can distinguish: a build that hard-coded
     * `ring-groups` for `answer` and `flows` for `menu` passes the test above
     * and fails this one.
     */
    public function test_a_line_with_nothing_set_up_points_at_the_line(): void
    {
        [$t, $number] = $this->line(configured: false);

        $steps = $this->describe($t, $number);

        $this->assertSame(
            ['pane' => 'numbers', 'id' => (string) $number->id],
            $steps['answer']['edit'],
            'a line that reaches nobody sent somebody to a list of healthy teams',
        );

        $this->assertSame(
            ['pane' => 'numbers', 'id' => (string) $number->id],
            $steps['menu']['edit'],
        );
    }

    /**
     * The chart and the list agree about where a thing is changed.
     *
     * They are two drawings of one call flow. A step that took somebody
     * somewhere the chart's own node does not would mean one of them is wrong,
     * and nothing else in the suite would notice — both would keep passing
     * their own assertions.
     */
    public function test_the_list_and_the_chart_name_the_same_panes(): void
    {
        [$t, $number] = $this->line();

        $steps = $this->describe($t, $number);
        $graph = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->graph($number->fresh()));

        $nodes = [];
        foreach ($graph['nodes'] as $node) {
            $nodes[(string) $node['id']] = $node;
        }

        // The three steps that have a node of their own. `hours` has one only
        // when a schedule is set, and `fallback` is drawn as an edge rather
        // than a box, so pairing those would be inventing a correspondence.
        foreach (['arrival' => 'arrival', 'menu' => 'menu', 'answer' => 'answer'] as $step => $nodeId) {
            $this->assertArrayHasKey($nodeId, $nodes, "the chart has no $nodeId node to compare");

            $this->assertSame(
                $nodes[$nodeId]['edit'],
                $steps[$step]['edit'],
                "the list and the chart disagree about where $step is changed",
            );
        }
    }

    /** @return array<string, array<string, mixed>> keyed by step */
    /**
     * Each step says whose value it is: the line's own, the company's default,
     * or nobody's — so the Call flow screen can say "follows the company"
     * from the server's own resolution rather than a client guess.
     */
    public function test_each_step_says_whether_the_line_or_the_company_set_it(): void
    {
        [$t, $number, , $group] = $this->line();

        // The menu moves to the company default; the ring group stays the
        // line's own; nothing sets hours anywhere.
        $this->inTenant($t['org'], function () use ($number) {
            $number->connection->forceFill(['default_flow_id' => $number->flow_id])->save();
            $number->forceFill(['flow_id' => null])->save();
        });

        $steps = $this->describe($t, $number);

        $this->assertSame('company', $steps['menu']['from']);
        $this->assertSame('line', $steps['answer']['from']);
        $this->assertNull($steps['hours']['from']);
        $this->assertNull($steps['arrival']['from']);
    }

    /**
     * The company's default group is judged the way a line's answer step is:
     * a group set but with nobody to ring reaches nobody.
     */
    public function test_the_company_default_says_whether_it_reaches_anybody(): void
    {
        [$t, $number, , $group] = $this->line();
        $reader = $this->userWithRole($t, 'Reader', ['phone.view', 'phone.settings.view']);

        $this->inTenant($t['org'], function () use ($number, $group) {
            $number->connection->forceFill(['default_ring_group_id' => $group->id])->save();

            // Somebody in the group is signed in and available.
            \App\Models\PhonePresence::query()->create([
                'connection_id' => $number->connection_id,
                'user_id' => $group->member_user_ids[0],
                'state' => \App\Models\PhonePresence::STATE_REGISTERED,
            ]);
        });

        $reaches = fn () => collect($this->signedInAs($reader)->getJson('/api/v1/phone/connections')
            ->assertOk()->json('data'))->firstWhere('id', $number->connection_id)['default_reaches'];

        $this->assertTrue($reaches());

        // And each line on the call-flow list names its company's account.
        $this->assertSame(
            $number->connection_id,
            $this->signedInAs($reader)->getJson('/api/v1/phone/call-flow')->assertOk()->json('data.0.connection_id'),
        );

        // The control: the same group, emptied.
        $this->inTenant($t['org'], fn () => $group->forceFill(['member_user_ids' => []])->save());
        $this->app['auth']->forgetGuards();

        $this->assertFalse($reaches());
    }

    /** The arrival step names the number as people write it. */
    public function test_the_arrival_step_formats_the_number(): void
    {
        [$t, $number] = $this->line();
        $this->inTenant($t['org'], fn () => $number->forceFill(['twilio_sid' => 'PNformatformatformatformatformatf'])->save());

        $this->assertSame(
            'A call arrives on +1 (305) 555-0113',
            $this->describe($t, $number)['arrival']['title'],
        );
    }

    private function describe(array $t, PhoneNumber $number): array
    {
        $described = $this->inTenant(
            $t['org'],
            fn () => (new TwimlRenderer)->describe($number->fresh()),
        );

        $out = [];
        foreach ($described as $step) {
            $out[(string) $step['step']] = $step;
        }

        return $out;
    }

    /** @return array{0: array<string,mixed>, 1: PhoneNumber, 2: ?PhoneFlow, 3: ?PhoneRingGroup} */
    private function line(bool $configured = true): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        [$number, $flow, $group] = $this->inTenant($t['org'], function () use ($t, $desk, $configured) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $flow = null;
            $group = null;

            if ($configured) {
                $flow = PhoneFlow::query()->create([
                    'connection_id' => $connection->id,
                    'name' => 'Main menu',
                    'nodes' => [
                        'type' => 'menu',
                        'prompt' => 'Press 1 for the desk.',
                        'options' => [['digit' => '1', 'action' => 'ring_group']],
                    ],
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
            }

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'flow_id' => $flow?->id,
                'ring_group_id' => $group?->id,
            ]);

            return [$number, $flow, $group];
        });

        return [$t, $number, $flow, $group];
    }
}
