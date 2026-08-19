<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'C-'.bin2hex(random_bytes(4)),
            'name' => fake()->name(),
            'email' => fake()->unique()->numerify('demo-####').'@example.test',
            'is_active' => true,
        ];
    }
}
