<?php

namespace Database\Factories;

use App\Models\VehicleInsurancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleInsurancePolicy>
 */
class VehicleInsurancePolicyFactory extends Factory
{
    protected $model = VehicleInsurancePolicy::class;

    public function definition(): array
    {
        return [
            'carrier' => fake()->company(),
            'policy_number' => strtoupper(fake()->bothify('POL-#####')),
            'started_on' => now()->toDateString(),
            'ended_on' => null,
        ];
    }
}
