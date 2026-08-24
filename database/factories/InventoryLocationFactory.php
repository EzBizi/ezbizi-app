<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLocation>
 */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'name' => 'Shop '.bin2hex(random_bytes(3)),
            'kind' => InventoryLocationKind::Shop,
            'status' => InventoryLocationStatus::Active,
        ];
    }
}
