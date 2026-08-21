<?php

namespace Database\Factories;

use App\Models\VehicleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleAssignment>
 */
class VehicleAssignmentFactory extends Factory
{
    protected $model = VehicleAssignment::class;

    public function definition(): array
    {
        return [
            'assigned_at' => now(),
            'released_at' => null,
        ];
    }
}
