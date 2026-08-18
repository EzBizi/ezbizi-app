<?php

namespace Tests\Feature\Support;

use App\Support\Contracts\FieldDefinition;
use Tests\TestCase;

/**
 * TODO #26 — a date field can be marked as an expiry.
 *
 * Older JSON without the key is not an expiry. The default is the safe
 * direction, same as required / sensitive / retired.
 */
class FieldExpiryTest extends TestCase
{
    public function test_a_date_without_the_flag_is_not_an_expiry(): void
    {
        $field = FieldDefinition::from([
            'key' => 'dob', 'label' => 'Date of birth', 'type' => 'date',
            'filled_by' => 'employee',
        ]);

        $this->assertFalse($field->isExpiry);
        $this->assertFalse($field->toArray()['is_expiry']);
    }

    public function test_the_flag_marks_a_date_as_an_expiry(): void
    {
        $field = FieldDefinition::from([
            'key' => 'first_aid', 'label' => 'First aid', 'type' => 'date',
            'filled_by' => 'employee', 'is_expiry' => true,
        ]);

        $this->assertTrue($field->isExpiry);
        $this->assertTrue($field->toArray()['is_expiry']);
    }

    public function test_a_non_date_cannot_be_an_expiry(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        \App\Support\Contracts\FieldSet::fromInput([[
            'key' => 'wage', 'label' => 'Wage', 'type' => 'number',
            'filled_by' => 'employer', 'is_expiry' => true,
        ]]);
    }
}
