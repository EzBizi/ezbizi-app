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

        $this->assertSame(
            ['Clay', 'Metal'],
            $set->toArray()[0]['fields'][0]['options'],
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
}
