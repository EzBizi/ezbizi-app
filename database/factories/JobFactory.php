<?php

namespace Database\Factories;

use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'J-'.bin2hex(random_bytes(4)),
            'title' => fake()->sentence(3),
            'status' => 'scheduled',
        ];
    }
}
