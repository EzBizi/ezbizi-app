<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\Job;
use App\Support\Contracts\FieldType as ContractFieldType;
use App\Support\Inspection\BindableFields;
use App\Support\Inspection\FieldSet;
use App\Support\Inspection\FieldType;
use App\Support\Inspection\LayoutPreset;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldSetTest extends TestCase
{
    public function test_an_unknown_type_is_refused(): void
    {
        try {
            FieldSet::fromInput([[
                'fields' => [[
                    'key' => 'crown',
                    'label' => 'Crown',
                    'type' => 'heading',
                ]],
            ]]);
            $this->fail('A made-up type must not become a field.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'heading',
                implode(' ', $e->errors()['screens.0.fields.0.type'] ?? []),
            );
        }
    }

    public function test_photo_max_count_defaults_to_one(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [[
                'key' => 'photos',
                'label' => 'Photos',
                'type' => 'photo',
            ]],
        ]]);

        $this->assertSame(1, $set->toArray()[0]['fields'][0]['max_count']);
    }

    /**
     * A choice with no list is refused.
     *
     * It used to be accepted, and nothing looked wrong because the run screen
     * drew every field as a text box. A dropdown makes the same definition a
     * control with nothing in it — a required question nobody can answer.
     */
    public function test_a_choice_with_no_options_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        FieldSet::fromInput([[
            'fields' => [[
                'key' => 'flue',
                'label' => 'Flue',
                'type' => 'choice',
            ]],
        ]]);
    }

    public function test_choice_options_are_trimmed_and_blanks_dropped(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [[
                'key' => 'flue',
                'label' => 'Flue',
                'type' => 'choice',
                'options' => ['  Clay  ', '', 'Metal', '   '],
            ]],
        ]]);

        // Options are objects now — value, label, variant and any fields the
        // option reveals — so everything downstream reads ONE shape whether the
        // author typed a plain list or built a pass/fail finding. A trimmed
        // string still becomes exactly what it always meant.
        $this->assertSame(
            ['Clay', 'Metal'],
            array_column($set->toArray()[0]['fields'][0]['options'], 'value'),
        );
        $this->assertSame(
            ['Clay', 'Metal'],
            array_column($set->toArray()[0]['fields'][0]['options'], 'label'),
        );
    }

    public function test_choice_multiple_is_a_bool(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [[
                'key' => 'damage',
                'label' => 'Damage',
                'type' => 'choice',
                'options' => ['yes', 'no'],
                'multiple' => true,
            ]],
        ]]);

        $this->assertTrue($set->toArray()[0]['fields'][0]['multiple']);
        $this->assertTrue(FieldType::takesOptions('choice'));
        $this->assertTrue(FieldType::allowsMultiple('choice'));
        $this->assertFalse(FieldType::allowsMultiple('text'));
    }

    public function test_required_if_on_another_screen_is_refused(): void
    {
        try {
            FieldSet::fromInput([
                [
                    'fields' => [[
                        'key' => 'damage',
                        'label' => 'Damage',
                        'type' => 'choice',
                        'options' => ['yes', 'no'],
                    ]],
                ],
                [
                    'fields' => [[
                        'key' => 'photos',
                        'label' => 'Photos',
                        'type' => 'photo',
                        'required_if' => ['field' => 'damage', 'equals' => 'yes'],
                    ]],
                ],
            ]);
            $this->fail('required_if must stay on the same screen.');
        } catch (ValidationException $e) {
            $flat = implode(' ', array_merge(...array_values($e->errors())));
            $this->assertStringContainsString('same screen', $flat);
        }
    }

    public function test_bindable_keys_are_customer_and_job_columns_not_invented(): void
    {
        $this->assertContains('customer.name', BindableFields::keys());
        $this->assertContains('job.scheduled_for', BindableFields::keys());
        $this->assertNotContains('customer.favourite_colour', BindableFields::keys());

        $customer = (new Customer)->getFillable();
        $job = (new Job)->getFillable();

        foreach (BindableFields::customerColumns() as $column) {
            $this->assertContains($column, $customer);
        }

        foreach (BindableFields::jobColumns() as $column) {
            $this->assertContains($column, $job);
        }
    }

    public function test_photo_is_inspection_only_and_the_default_preset_is_step_strip(): void
    {
        $this->assertContains('photo', FieldType::keys());
        $this->assertNotContains('photo', ContractFieldType::keys());
        $this->assertSame('step_strip', LayoutPreset::DEFAULT);
        $this->assertSame(
            ['step_strip', 'document', 'quiet_stack'],
            LayoutPreset::keys(),
        );
    }

    // ── visible_if ───────────────────────────────────────────────

    /**
     * A condition reaches ACROSS screens, and that is the whole point.
     *
     * required_if is deliberately local; visible_if is not. Answering "masonry"
     * on screen one has to remove the wood-stove questions from screen four, or
     * the A1 inspection cannot be expressed at all.
     */
    public function test_a_condition_may_name_a_field_on_another_screen(): void
    {
        $set = FieldSet::fromInput([
            ['fields' => [
                ['key' => 'system_type', 'label' => 'System', 'type' => 'choice',
                    'options' => ['masonry', 'wood']],
            ]],
            ['fields' => [
                ['key' => 'stove', 'label' => 'Stove', 'type' => 'text',
                    'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood']],
            ]],
        ]);

        $this->assertSame(
            ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'],
            $set->toArray()[1]['fields'][0]['visible_if'],
        );
    }

    public function test_a_screen_may_carry_a_condition(): void
    {
        $set = FieldSet::fromInput([
            ['fields' => [
                ['key' => 'level', 'label' => 'Level', 'type' => 'choice', 'options' => ['1', '2']],
            ]],
            [
                'visible_if' => ['field' => 'level', 'op' => 'eq', 'value' => '2'],
                'fields' => [['key' => 'crown', 'label' => 'Crown', 'type' => 'text']],
            ],
        ]);

        $this->assertSame('level', $set->toArray()[1]['visible_if']['field']);
    }

    public function test_a_condition_pointing_at_nothing_is_refused(): void
    {
        try {
            FieldSet::fromInput([
                ['fields' => [
                    ['key' => 'crown', 'label' => 'Crown', 'type' => 'text',
                        'visible_if' => ['field' => 'not_a_field', 'op' => 'eq', 'value' => 'x']],
                ]],
            ]);
            $this->fail('a condition naming no field should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'no field in this inspection has that key',
                implode(' ', $e->errors()['screens.0.fields.0.visible_if'] ?? []),
            );
        }
    }

    /**
     * The narrow refusal: an ambiguous key stops the CONDITION, not the form.
     *
     * Duplicate keys across screens still publish exactly as they always did —
     * refusing them outright would be a tightening that stops somebody working
     * on a form they already have, for a reason unrelated to what they were
     * doing. Only a condition that would have to guess is refused, and it names
     * both screens, because "ambiguous" without saying where is unfixable.
     */
    public function test_a_condition_naming_a_key_two_screens_share_is_refused(): void
    {
        $duplicated = [
            ['fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']]],
            ['fields' => [['key' => 'note', 'label' => 'Note again', 'type' => 'text']]],
        ];

        // The duplicate itself is fine, and keeps publishing.
        $this->assertCount(2, FieldSet::fromInput($duplicated)->toArray());

        try {
            FieldSet::fromInput([
                ...$duplicated,
                ['fields' => [
                    ['key' => 'crown', 'label' => 'Crown', 'type' => 'text',
                        'visible_if' => ['field' => 'note', 'op' => 'eq', 'value' => 'x']],
                ]],
            ]);
            $this->fail('an ambiguous condition should be refused');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['screens.2.fields.0.visible_if'] ?? []);
            $this->assertStringContainsString('2 screens have a field with that key', $message);
            $this->assertStringContainsString('screens 1 and 2', $message);
        }
    }

    public function test_a_malformed_condition_is_refused(): void
    {
        try {
            FieldSet::fromInput([
                ['fields' => [
                    ['key' => 'a', 'label' => 'A', 'type' => 'text'],
                    ['key' => 'b', 'label' => 'B', 'type' => 'text',
                        'visible_if' => ['field' => 'a', 'op' => 'matches', 'value' => 'x']],
                ]],
            ]);
            $this->fail('an unknown operator should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'is not something a condition can do',
                implode(' ', $e->errors()['screens.0.fields.1.visible_if'] ?? []),
            );
        }
    }

    /** Audit 11-27: the Builder offers a follow-up question as a condition target; the server must take it. */
    public function test_a_condition_may_name_a_follow_up_question(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [
                ['key' => 'crown', 'label' => 'Crown', 'type' => 'choice', 'options' => [
                    ['value' => 'ok', 'label' => 'OK'],
                    ['value' => 'bad', 'label' => 'Bad', 'fields' => [
                        ['key' => 'crack', 'label' => 'Cracked?', 'type' => 'choice', 'options' => [['value' => 'yes', 'label' => 'Yes']]],
                    ]],
                ]],
                ['key' => 'seal', 'label' => 'Seal it', 'type' => 'text', 'visible_if' => ['op' => 'eq', 'field' => 'crack', 'value' => 'yes']],
            ],
        ]]);

        $this->assertSame('crack', $set->toArray()[0]['fields'][1]['visible_if']['field']);
    }
}
