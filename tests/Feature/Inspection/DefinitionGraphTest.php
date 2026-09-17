<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\DefinitionGraph;
use PHPUnit\Framework\TestCase;

/**
 * The picture agrees with the walk, and names the branch that cannot open.
 *
 * No database: `DefinitionGraph::of` takes the screens array and nothing else,
 * which is the same input `RequiredFields::firstMissing` takes. If this ever
 * needs a tenant, the graph has started reading something the run does not.
 */
class DefinitionGraphTest extends TestCase
{
    /**
     * THE CASE THE CHART EXISTS FOR.
     *
     * A condition naming `roof_state` when the field is called `roof_condition`
     * — somebody renamed it three edits ago. In the builder's list both screens
     * look identical. `Condition::passes` returns false for the second forever,
     * so a technician is never asked and nobody is told.
     *
     * Asserted on the STATE rather than on a count of nodes, because a graph
     * that dropped the dead screen entirely would also make a count test pass
     * while hiding the exact thing this is for.
     */
    public function test_a_condition_naming_a_field_that_does_not_exist_is_dead(): void
    {
        $graph = DefinitionGraph::of([
            ['name' => 'Exterior', 'fields' => [
                ['key' => 'roof_condition', 'label' => 'Roof condition', 'type' => 'text'],
            ]],
            ['name' => 'Roof detail', 'visible_if' => ['field' => 'roof_state', 'op' => 'eq', 'value' => 'Poor'], 'fields' => [
                ['key' => 'roof_photo', 'label' => 'Photograph the roof', 'type' => 'text'],
            ]],
        ]);

        $states = self::statesByTitle($graph);

        $this->assertSame('dead', $states['Roof detail'], 'a condition on a field nobody declared can never open');
        $this->assertSame('ok', $states['Exterior'], 'the screen next to it is fine and must not be tarred');
    }

    /** The control: the same shape with the name spelled right is not dead. */
    public function test_the_same_condition_spelled_correctly_is_ok(): void
    {
        $graph = DefinitionGraph::of([
            ['name' => 'Exterior', 'fields' => [
                ['key' => 'roof_condition', 'label' => 'Roof condition', 'type' => 'text'],
            ]],
            ['name' => 'Roof detail', 'visible_if' => ['field' => 'roof_condition', 'op' => 'eq', 'value' => 'Poor'], 'fields' => [
                ['key' => 'roof_photo', 'label' => 'Photograph the roof', 'type' => 'text'],
            ]],
        ]);

        $this->assertSame('ok', self::statesByTitle($graph)['Roof detail']);
    }

    /**
     * A condition may name a field nested under an option, and that is not dead.
     *
     * `fieldKeys` reads all three levels through `OptionSet::normalise`. Reading
     * only the top level would call this dead — the failure that would make the
     * chart cry wolf about working definitions, which is worse than silence
     * because it trains people to ignore the colour.
     */
    public function test_a_condition_on_an_option_revealed_field_is_not_dead(): void
    {
        $graph = DefinitionGraph::of([
            ['name' => 'Flue', 'fields' => [
                [
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'select',
                    'options' => [
                        ['value' => 'Damaged', 'fields' => [
                            ['key' => 'damage_extent', 'label' => 'How bad', 'type' => 'text'],
                        ]],
                    ],
                ],
            ]],
            ['name' => 'Quote', 'visible_if' => ['field' => 'damage_extent', 'op' => 'ne', 'value' => ''], 'fields' => [
                ['key' => 'price', 'label' => 'Price', 'type' => 'text'],
            ]],
        ]);

        $this->assertSame('ok', self::statesByTitle($graph)['Quote']);
    }

    /** Every branch is drawn with no answers — that is what the author is editing. */
    public function test_the_static_picture_draws_branches_nobody_took(): void
    {
        $graph = DefinitionGraph::of([
            ['name' => 'Flue', 'fields' => [
                [
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'select',
                    'options' => [
                        ['value' => 'Damaged', 'fields' => [['key' => 'extent', 'label' => 'How bad', 'type' => 'text']]],
                        ['value' => 'Sound', 'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']]],
                    ],
                ],
            ]],
        ]);

        $titles = array_column($graph['nodes'], 'title');

        $this->assertContains('How bad', $titles);
        $this->assertContains('Note', $titles, 'a branch nobody chose is still a branch the author is editing');

        foreach ($graph['nodes'] as $node) {
            $this->assertArrayNotHasKey(
                'on_path',
                $node,
                'with no answers the graph knows of no path, and must not imply one',
            );
        }
    }

    /**
     * With answers, each node says whether THIS run reached it.
     *
     * The seam for the live overlay. Asserted on a node that is off the path as
     * well as one on it — a graph that stamped `true` on everything would pass
     * a test that only looked at the answered branch.
     */
    public function test_answers_mark_the_path_this_run_took(): void
    {
        $screens = [
            ['name' => 'Exterior', 'fields' => [
                ['key' => 'roof_condition', 'label' => 'Roof condition', 'type' => 'text'],
            ]],
            ['name' => 'Roof detail', 'visible_if' => ['field' => 'roof_condition', 'op' => 'eq', 'value' => 'Poor'], 'fields' => [
                ['key' => 'roof_photo', 'label' => 'Photograph the roof', 'type' => 'text'],
            ]],
        ];

        $graph = DefinitionGraph::of($screens, ['roof_condition' => 'Good']);

        $onPath = [];
        foreach ($graph['nodes'] as $node) {
            $onPath[$node['title']] = $node['on_path'] ?? null;
        }

        $this->assertTrue($onPath['Exterior'], 'an unconditional screen is always on the path');
        $this->assertFalse($onPath['Roof detail'], 'the roof was Good, so this was never shown');
    }

    /**
     * A nested field and a top-level field of the same name edit in two places.
     *
     * Phone's most expensive lesson, read across: their voicemail BOX and
     * voicemail OPTION were both `kind: voicemail`, and a kind-to-pane map sent
     * somebody to a greeting they had not clicked on and looked like it had
     * worked. Pinning the PAIR, not the kind.
     */
    public function test_the_edit_target_is_not_derived_from_the_kind(): void
    {
        $graph = DefinitionGraph::of([
            ['name' => 'Flue', 'fields' => [
                ['key' => 'note', 'label' => 'Top level note', 'type' => 'text'],
                [
                    'key' => 'liner', 'label' => 'Liner', 'type' => 'select',
                    'options' => [
                        ['value' => 'Damaged', 'fields' => [['key' => 'note', 'label' => 'Nested note', 'type' => 'text']]],
                    ],
                ],
            ]],
        ]);

        $edits = [];
        foreach ($graph['nodes'] as $node) {
            $edits[$node['title']] = $node['edit'];
        }

        $this->assertNotSame(
            $edits['Top level note'],
            $edits['Nested note'],
            'two fields keyed "note" are edited in different places and must not share an edit target',
        );
        $this->assertSame('field', $edits['Top level note']['pane']);
        $this->assertSame('option_field', $edits['Nested note']['pane']);
    }

    /** @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
    private static function statesByTitle(array $graph): array
    {
        $out = [];
        foreach ($graph['nodes'] as $node) {
            $out[$node['title']] = $node['state'];
        }

        return $out;
    }
}
