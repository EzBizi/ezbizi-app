<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\BoundAnswers;
use App\Support\Inspection\FieldSet;
use App\Support\Inspection\RequiredFields;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fields that live inside one option, and the pass/fail colour of an option.
 *
 * ── The pattern this exists for ──────────────────────────────────
 *
 * A1's inspection generates every finding the same way: Pass / Fail /
 * Not-Applicable, where the FAIL branch carries a required photograph, an
 * explanation and priced work. A technician cannot record a failure and move
 * on — the evidence is structurally part of failing.
 *
 * That is the single most valuable idea in the tool being ported, and it is why
 * nesting is worth the complexity rather than being a flourish.
 */
class OptionFieldsTest extends TestCase
{
    // ── the shape ────────────────────────────────────────────────

    /**
     * A plain string option still works, and that is not a courtesy.
     *
     * Every definition that exists has string options. If the richer form
     * replaced them rather than joining them, this change would refuse every
     * form in the product on the day it shipped.
     */
    public function test_a_plain_string_option_is_still_accepted(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [[
                'key' => 'flue', 'label' => 'Flue', 'type' => 'choice',
                'options' => ['Clay', 'Metal'],
            ]],
        ]]);

        $options = $set->toArray()[0]['fields'][0]['options'];

        // Normalised to the full shape so everything downstream reads one form.
        $this->assertSame('Clay', $options[0]['value']);
        $this->assertSame('Clay', $options[0]['label']);
        $this->assertSame('neutral', $options[0]['variant']);
        $this->assertSame([], $options[0]['fields']);
    }

    public function test_an_option_can_carry_a_variant_and_its_own_fields(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [[
                'key' => 'crown', 'label' => 'Crown condition', 'type' => 'choice',
                'options' => [
                    ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                    [
                        'value' => 'not-ok',
                        'label' => 'Does not meet standards',
                        'variant' => 'fail',
                        'fields' => [
                            ['key' => 'crown_photo', 'label' => 'Photograph',
                             'type' => 'photo', 'required' => true],
                            ['key' => 'crown_note', 'label' => 'Explanation', 'type' => 'text'],
                        ],
                    ],
                ],
            ]],
        ]]);

        $options = $set->toArray()[0]['fields'][0]['options'];

        $this->assertSame('pass', $options[0]['variant']);
        $this->assertSame('fail', $options[1]['variant']);
        $this->assertCount(2, $options[1]['fields']);
        $this->assertSame('crown_photo', $options[1]['fields'][0]['key']);
    }

    public function test_an_unknown_variant_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        FieldSet::fromInput([[
            'fields' => [[
                'key' => 'crown', 'label' => 'Crown', 'type' => 'choice',
                'options' => [['value' => 'ok', 'label' => 'OK', 'variant' => 'chartreuse']],
            ]],
        ]]);
    }

    /**
     * One level of nesting, and no more.
     *
     * The old tool never goes deeper. An uncapped tree is a Builder nobody can
     * draw, a validator that recurses on request data, and a run screen with no
     * sensible layout.
     */
    public function test_a_field_inside_an_option_cannot_itself_nest(): void
    {
        try {
            FieldSet::fromInput([[
                'fields' => [[
                    'key' => 'crown', 'label' => 'Crown', 'type' => 'choice',
                    'options' => [[
                        'value' => 'not-ok', 'label' => 'Bad', 'variant' => 'fail',
                        'fields' => [[
                            'key' => 'why', 'label' => 'Why', 'type' => 'choice',
                            'options' => [[
                                'value' => 'a', 'label' => 'A',
                                'fields' => [['key' => 'deeper', 'label' => 'D', 'type' => 'text']],
                            ]],
                        ]],
                    ]],
                ]],
            ]]);
            $this->fail('two levels of nesting should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'one level',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }
    }

    /** A nested key collides with a top-level one the same way any two would. */
    public function test_a_nested_field_key_must_be_unique_on_its_screen(): void
    {
        $this->expectException(ValidationException::class);

        FieldSet::fromInput([[
            'fields' => [
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
                [
                    'key' => 'crown', 'label' => 'Crown', 'type' => 'choice',
                    'options' => [[
                        'value' => 'not-ok', 'label' => 'Bad', 'variant' => 'fail',
                        'fields' => [['key' => 'note', 'label' => 'Note again', 'type' => 'text']],
                    ]],
                ],
            ],
        ]]);
    }

    // ── what nesting means to a run ──────────────────────────────

    /**
     * A nested field is required only while its option is chosen.
     *
     * This is the whole mechanism: choosing "fail" makes the photograph
     * mandatory, and choosing "pass" makes it not exist.
     */
    public function test_a_nested_required_field_binds_only_under_its_option(): void
    {
        $screens = $this->crownScreens();

        $this->assertNull(
            RequiredFields::firstMissing($screens, ['crown' => 'ok']),
            'passing needs no photograph',
        );

        $this->assertSame(
            'Photograph',
            RequiredFields::firstMissing($screens, ['crown' => 'not-ok']),
            'failing demands its evidence',
        );

        $this->assertNull(
            RequiredFields::firstMissing($screens, ['crown' => 'not-ok', 'crown_photo' => ['a']]),
        );
    }

    /**
     * An answer under an option nobody chose is dropped at submit.
     *
     * A technician marks it failed, photographs it, then changes their mind and
     * marks it passing. The report must not carry a photograph of a fault it
     * says does not exist.
     */
    public function test_an_answer_under_an_unchosen_option_is_dropped(): void
    {
        $frozen = BoundAnswers::freeze(null, null, $this->crownScreens(), [
            'crown' => 'ok',
            'crown_photo' => ['att-1'],
        ]);

        $this->assertArrayNotHasKey('crown_photo', $frozen);
        $this->assertSame('ok', $frozen['crown']);
    }

    public function test_an_answer_under_the_chosen_option_is_kept(): void
    {
        $frozen = BoundAnswers::freeze(null, null, $this->crownScreens(), [
            'crown' => 'not-ok',
            'crown_photo' => ['att-1'],
        ]);

        $this->assertSame(['att-1'], $frozen['crown_photo']);
    }

    /**
     * A MULTI-choice reveals every chosen option's fields.
     *
     * "Cracked bricks" and "loose mortar" both selected has to show both
     * follow-ups, not the first one.
     */
    public function test_a_multi_choice_reveals_each_chosen_options_fields(): void
    {
        $screens = [[
            'fields' => [[
                'key' => 'repairs', 'label' => 'Repairs', 'type' => 'choice', 'multiple' => true,
                'options' => [
                    ['value' => 'cracked', 'label' => 'Cracked bricks', 'variant' => 'fail',
                     'fields' => [['key' => 'cracked_note', 'label' => 'Cracked note',
                                   'type' => 'text', 'required' => true]]],
                    ['value' => 'mortar', 'label' => 'Loose mortar', 'variant' => 'fail',
                     'fields' => [['key' => 'mortar_note', 'label' => 'Mortar note',
                                   'type' => 'text', 'required' => true]]],
                ],
            ]],
        ]];

        $this->assertSame(
            'Cracked note',
            RequiredFields::firstMissing($screens, ['repairs' => ['cracked', 'mortar']]),
        );

        $this->assertSame(
            'Mortar note',
            RequiredFields::firstMissing($screens, [
                'repairs' => ['cracked', 'mortar'], 'cracked_note' => 'x',
            ]),
        );

        $this->assertNull(
            RequiredFields::firstMissing($screens, ['repairs' => ['cracked'], 'cracked_note' => 'x']),
            'an option nobody chose asks nothing',
        );
    }

    /** @return list<array<string, mixed>> */
    private function crownScreens(): array
    {
        return [[
            'name' => 'Crown',
            'fields' => [[
                'key' => 'crown', 'label' => 'Crown condition', 'type' => 'choice',
                'options' => [
                    ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                    [
                        'value' => 'not-ok', 'label' => 'Does not meet standards', 'variant' => 'fail',
                        'fields' => [
                            ['key' => 'crown_photo', 'label' => 'Photograph',
                             'type' => 'photo', 'required' => true],
                        ],
                    ],
                ],
            ]],
        ]];
    }
}
