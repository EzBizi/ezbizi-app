<?php

namespace Tests\Feature\Support;

use App\Support\Contracts\FieldSet;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldShownWhenTest extends TestCase
{
    public function test_a_question_can_wait_on_a_choice(): void
    {
        $set = FieldSet::fromInput([
            [
                'key' => 'pay_basis', 'label' => 'Pay basis', 'type' => 'choice',
                'options' => ['Hourly', 'Salary'], 'filled_by' => 'employer', 'required' => true,
            ],
            [
                'key' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'number',
                'filled_by' => 'employer', 'required' => true,
                'shown_when' => ['key' => 'pay_basis', 'is' => 'hourly'],
            ],
        ]);

        $hourly = $set->keyed()['hourly_rate'];
        $this->assertSame(['key' => 'pay_basis', 'is' => 'hourly'], $hourly->shownWhen);
        $this->assertTrue($hourly->isShownGiven(['pay_basis' => 'Hourly']));
        $this->assertFalse($hourly->isShownGiven(['pay_basis' => 'Salary']));
    }

    public function test_the_gate_must_exist_on_the_form(): void
    {
        try {
            FieldSet::fromInput([[
                'key' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'number',
                'filled_by' => 'employer', 'required' => true,
                'shown_when' => ['key' => 'pay_basis', 'is' => 'hourly'],
            ]]);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fields.0.shown_when', $e->errors());
        }
    }
}
