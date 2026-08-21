<?php

namespace Database\Factories;

use App\Models\VehicleOwnershipPeriod;
use App\Support\Fleet\VehicleOwnershipKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleOwnershipPeriod>
 */
class VehicleOwnershipPeriodFactory extends Factory
{
    protected $model = VehicleOwnershipPeriod::class;

    public function definition(): array
    {
        return [
            'kind' => VehicleOwnershipKind::Owned,
            'counterparty' => fake()->company(),
            'started_on' => now()->toDateString(),
            'ended_on' => null,
        ];
    }
}
