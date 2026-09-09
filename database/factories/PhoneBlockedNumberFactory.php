<?php

namespace Database\Factories;

use App\Models\PhoneBlockedNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneBlockedNumber>
 */
class PhoneBlockedNumberFactory extends Factory
{
    protected $model = PhoneBlockedNumber::class;

    public function definition(): array
    {
        return [
            'e164' => '+1305555'.fake()->numerify('####'),
            'reason' => 'Robocall',
            'blocked_by' => null,
        ];
    }
}
