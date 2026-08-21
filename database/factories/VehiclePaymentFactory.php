<?php

namespace Database\Factories;

use App\Models\VehiclePayment;
use App\Support\Fleet\VehiclePaymentCategory;
use App\Support\Fleet\VehiclePaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehiclePayment>
 */
class VehiclePaymentFactory extends Factory
{
    protected $model = VehiclePayment::class;

    public function definition(): array
    {
        return [
            'category' => VehiclePaymentCategory::Maintenance,
            'amount_cents' => 10000,
            'occurred_on' => now()->toDateString(),
            'method' => VehiclePaymentMethod::Cash,
        ];
    }
}