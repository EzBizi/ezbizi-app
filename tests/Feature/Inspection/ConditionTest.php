<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\Condition;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Whether a field or a screen is shown at all.
 *
 * ── Why this is not `required_if` ────────────────────────────────
 *
 * `required_if` answers "must this be answered", and it stays. This answers
 * "is this asked", which is a different question: a masonry inspection does not
 * make the wood-stove questions optional, it does not ask them.
 *
 * The A1 inspection this engine exists to carry branches five ways on system
 * type and is unusable without it — roughly a hundred of its hundred and twenty
 * fields sit behind one.
 */
class ConditionTest extends TestCase
{
    // ── the operators ────────────────────────────────────────────

    public function test_eq_and_ne(): void
    {
        $answers = ['system_type' => 'masonry'];

        $this->assertTrue(Condition::passes(
            ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'], $answers));
        $this->assertFalse(Condition::passes(
            ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'], $answers));
        $this->assertTrue(Condition::passes(
            ['field' => 'system_type', 'op' => 'ne', 'value' => 'wood'], $answers));
    }

    public function test_in_and_not_in(): void
    {
        $answers = ['system_type' => 'wood'];

        $this->assertTrue(Condition::passes(
            ['field' => 'system_type', 'op' => 'in', 'values' => ['masonry', 'wood']], $answers));
        $this->assertFalse(Condition::passes(
            ['field' => 'system_type', 'op' => 'not_in', 'values' => ['masonry', 'wood']], $answers));
    }

    /**
     * A multi-answer field matches if ANY of its values match.
     *
     * A `choice` with `multiple` answers as a list, and the alternative —
     * comparing a list to a string, which is never equal — would make every
     * condition on a multi-select silently false forever.
     */
    public function test_a_multi_answer_matches_on_any_of_its_values(): void
    {
        $answers = ['repairs' => ['cracked-bricks', 'loose-mortar']];

        $this->assertTrue(Condition::passes(
            ['field' => 'repairs', 'op' => 'eq', 'value' => 'loose-mortar'], $answers));
        $this->assertTrue(Condition::passes(
            ['field' => 'repairs', 'op' => 'in', 'values' => ['loose-mortar']], $answers));
        $this->assertFalse(Condition::passes(
            ['field' => 'repairs', 'op' => 'eq', 'value' => 'spalling'], $answers));
    }

    public function test_and_or_nest(): void
    {
        $answers = ['system_type' => 'masonry', 'level' => '2'];

        $this->assertTrue(Condition::passes([
            'op' => 'and',
            'conditions' => [
                ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'],
                ['field' => 'level', 'op' => 'eq', 'value' => '2'],
            ],
        ], $answers));

        $this->assertFalse(Condition::passes([
            'op' => 'and',
            'conditions' => [
                ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'],
                ['field' => 'level', 'op' => 'eq', 'value' => '1'],
            ],
        ], $answers));

        $this->assertTrue(Condition::passes([
            'op' => 'or',
            'conditions' => [
                ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'],
                ['field' => 'level', 'op' => 'eq', 'value' => '2'],
            ],
        ], $answers));
    }

    // ── the two answers that are easy to get wrong ───────────────

    /**
     * No condition means shown. This is the default for every existing field.
     */
    public function test_an_absent_condition_shows_the_field(): void
    {
        $this->assertTrue(Condition::passes(null, []));
        $this->assertTrue(Condition::passes([], []));
    }

    /**
     * An UNANSWERED field does not satisfy `eq`, and DOES satisfy `ne`.
     *
     * This is the case that decides what a technician sees when they open a
     * fresh run: nothing is answered yet. A `ne` that read false on an empty
     * answer would hide every field whose condition is "not electric" until
     * something was typed — so the form would open almost blank and fill in as
     * you went, which is not what anybody drew.
     */
    public function test_an_unanswered_field_fails_eq_and_passes_ne(): void
    {
        $this->assertFalse(Condition::passes(
            ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'], []));
        $this->assertTrue(Condition::passes(
            ['field' => 'system_type', 'op' => 'ne', 'value' => 'masonry'], []));
        $this->assertFalse(Condition::passes(
            ['field' => 'system_type', 'op' => 'in', 'values' => ['masonry']], []));
        $this->assertTrue(Condition::passes(
            ['field' => 'system_type', 'op' => 'not_in', 'values' => ['masonry']], []));
    }

    // ── validation, at the Builder boundary ──────────────────────

    public function test_an_unknown_operator_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        Condition::validate(['field' => 'x', 'op' => 'matches', 'value' => 'y'], 'visible_if');
    }

    public function test_a_condition_naming_no_field_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        Condition::validate(['op' => 'eq', 'value' => 'y'], 'visible_if');
    }

    /**
     * Depth is capped, and the cap is the reason it can be evaluated at all.
     *
     * An uncapped tree is a Builder nobody can draw and a validator that can be
     * handed a structure deep enough to exhaust the stack — from a request body.
     */
    public function test_a_condition_nested_too_deep_is_refused(): void
    {
        $deep = ['field' => 'x', 'op' => 'eq', 'value' => '1'];
        for ($i = 0; $i < 6; $i++) {
            $deep = ['op' => 'and', 'conditions' => [$deep]];
        }

        $this->expectException(ValidationException::class);

        Condition::validate($deep, 'visible_if');
    }

    public function test_a_reasonable_nest_is_accepted(): void
    {
        $ok = Condition::validate([
            'op' => 'or',
            'conditions' => [
                ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'],
                [
                    'op' => 'and',
                    'conditions' => [
                        ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'],
                        ['field' => 'level', 'op' => 'in', 'values' => ['2']],
                    ],
                ],
            ],
        ], 'visible_if');

        $this->assertSame('or', $ok['op']);
        $this->assertCount(2, $ok['conditions']);
    }

    /** The keys a condition mentions, so the Builder can check they exist. */
    public function test_it_reports_the_fields_it_names(): void
    {
        $keys = Condition::fieldsNamed([
            'op' => 'and',
            'conditions' => [
                ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'],
                ['field' => 'level', 'op' => 'eq', 'value' => '2'],
            ],
        ]);

        sort($keys);
        $this->assertSame(['level', 'system_type'], $keys);
    }
}
