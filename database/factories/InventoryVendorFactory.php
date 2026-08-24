<?php

namespace Database\Factories;

use App\Models\InventoryVendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryVendor>
 */
class InventoryVendorFactory extends Factory
{
    protected $model = InventoryVendor::class;

    public function definition(): array
    {
        return [
            'name' => 'Vendor '.bin2hex(random_bytes(3)),
        ];
    }
}
