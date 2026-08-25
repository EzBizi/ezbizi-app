<?php

namespace Database\Factories;

use App\Models\ServiceLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceLabel> */
class ServiceLabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Popular',
        ];
    }
}
