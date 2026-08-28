<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\BoundAnswers;
use App\Support\Inspection\RequiredFields;
use Tests\TestCase;

/**
 * What a condition does to a RUN — the two places it can go wrong.
 *
 * ConditionTest proves the operators. This proves the consequences, and they
 * are the reason the engine is worth being careful about: one of them blocks a
 * technician on a roof, the other puts an answer on a frozen report that the
 * report does not show.
 */
class ConditionalRunTest extends TestCase
{
    /**
     * A HIDDEN field is not a missing answer.
     *
     * The likeliest bug in the whole engine, and the worst one: a masonry
     * inspection blocked at Submit by an unanswered wood-stove question that
     * was never on screen. The technician is told "Wood stove type needs an
     * answer before you can continue" about a field they cannot see, and there
     * is nothing they can do about it.
     */
    public function test_a_hidden_required_field_does_not_block_submit(): void
    {
        $screens = [[
            'name' => 'System',
            'fields' => [
                ['key' => 'system_type', 'label' => 'System type', 'type' => 'choice',
                 'options' => ['masonry', 'wood'], 'required' => true],
                ['key' => 'wood_stove_type', 'label' => 'Wood stove type', 'type' => 'text',
                 'required' => true,
                 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood']],
            ],
        ]];

        $this->assertNull(
            RequiredFields::firstMissing($screens, ['system_type' => 'masonry']),
            'a masonry inspection must not be blocked by a wood-stove question',
        );

        // And when it IS shown, it is required again — the condition removes the
        // question, it does not excuse it.
        $this->assertSame(
            'Wood stove type',
            RequiredFields::firstMissing($screens, ['system_type' => 'wood']),
        );
    }

    /** A field on a hidden SCREEN is hidden too. */
    public function test_a_field_on_a_hidden_screen_does_not_block_submit(): void
    {
        $screens = [
            [
                'name' => 'Level',
                'fields' => [
                    ['key' => 'level', 'label' => 'Level', 'type' => 'choice',
                     'options' => ['1', '2'], 'required' => true],
                ],
            ],
            [
                'name' => 'Exterior',
                'visible_if' => ['field' => 'level', 'op' => 'eq', 'value' => '2'],
                'fields' => [
                    ['key' => 'crown', 'label' => 'Crown', 'type' => 'text', 'required' => true],
                ],
            ],
        ];

        $this->assertNull(RequiredFields::firstMissing($screens, ['level' => '1']));
        $this->assertSame('Crown', RequiredFields::firstMissing($screens, ['level' => '2']));
    }

    /**
     * An answer to a question the report will not ask is DROPPED at submit.
     *
     * A technician answers the wood-stove branch, then changes the system type
     * to masonry. Without this the report freezes an answer it never renders —
     * evidence of a question that was not asked, sitting in `answers` forever,
     * and turning up in an export or a §9 purge report as something the visit
     * apparently found.
     */
    public function test_an_answer_to_a_hidden_field_is_dropped_at_submit(): void
    {
        $screens = [[
            'name' => 'System',
            'fields' => [
                ['key' => 'system_type', 'label' => 'System type', 'type' => 'choice',
                 'options' => ['masonry', 'wood']],
                ['key' => 'wood_stove_type', 'label' => 'Wood stove type', 'type' => 'text',
                 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood']],
            ],
        ]];

        $frozen = BoundAnswers::freeze(null, null, $screens, [
            'system_type' => 'masonry',
            'wood_stove_type' => 'Insert',
        ]);

        $this->assertArrayNotHasKey('wood_stove_type', $frozen,
            'an answer the report will not show must not be frozen into it');
        $this->assertSame('masonry', $frozen['system_type']);
    }

    public function test_a_visible_answer_is_kept(): void
    {
        $screens = [[
            'name' => 'System',
            'fields' => [
                ['key' => 'system_type', 'label' => 'System type', 'type' => 'choice',
                 'options' => ['masonry', 'wood']],
                ['key' => 'wood_stove_type', 'label' => 'Wood stove type', 'type' => 'text',
                 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood']],
            ],
        ]];

        $frozen = BoundAnswers::freeze(null, null, $screens, [
            'system_type' => 'wood',
            'wood_stove_type' => 'Insert',
        ]);

        $this->assertSame('Insert', $frozen['wood_stove_type']);
    }

    /**
     * Dropping is by the field's OWN visibility, resolved against the final
     * answers — not against a half-filled draft.
     *
     * A chain: level 2 shows the exterior screen, which shows the crown
     * question. Set level back to 1 and BOTH go, in one pass.
     */
    public function test_a_hidden_screens_answers_are_dropped_too(): void
    {
        $screens = [
            [
                'name' => 'Level',
                'fields' => [['key' => 'level', 'label' => 'Level', 'type' => 'choice',
                              'options' => ['1', '2']]],
            ],
            [
                'name' => 'Exterior',
                'visible_if' => ['field' => 'level', 'op' => 'eq', 'value' => '2'],
                'fields' => [['key' => 'crown', 'label' => 'Crown', 'type' => 'text']],
            ],
        ];

        $frozen = BoundAnswers::freeze(null, null, $screens, [
            'level' => '1',
            'crown' => 'Cracked at the wash',
        ]);

        $this->assertArrayNotHasKey('crown', $frozen);
    }

    /**
     * An answer with no field behind it at all is left alone.
     *
     * Freeze must not become a whitelist. A version's screens are the questions
     * it asked; an answer key that is not among them belongs to something else
     * — a photo attachment list, a future field type this build predates — and
     * silently dropping it would be this method deleting data it does not
     * understand.
     */
    public function test_an_answer_with_no_matching_field_is_untouched(): void
    {
        $screens = [[
            'name' => 'System',
            'fields' => [['key' => 'system_type', 'label' => 'System type', 'type' => 'text']],
        ]];

        $frozen = BoundAnswers::freeze(null, null, $screens, [
            'system_type' => 'masonry',
            'something_else' => 'kept',
        ]);

        $this->assertSame('kept', $frozen['something_else']);
    }
}
