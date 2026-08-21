<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'number' => 'V-'.bin2hex(random_bytes(4)),
            'year' => 2021,
            'make' => 'Ford',
            'model' => 'Transit',
            'plate' => strtoupper(fake()->bothify('???-####')),
            'body_type' => VehicleBodyType::Van,
            'fuel_type' => VehicleFuelType::Gas,
            'status' => VehicleStatus::Active,
        ];
    }
}
