<?php

namespace Database\Factories;

use App\Models\VehicleServiceVisit;
use App\Support\Fleet\VehicleServiceKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleServiceVisit>
 */
class VehicleServiceVisitFactory extends Factory
{
    protected $model = VehicleServiceVisit::class;

    public function definition(): array
    {
        return [
            'kind' => VehicleServiceKind::Oil,
            'occurred_on' => now()->toDateString(),
        ];
    }
}
