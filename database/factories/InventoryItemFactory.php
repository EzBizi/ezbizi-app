<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'number' => 'I-'.bin2hex(random_bytes(4)),
            'name' => '6 mil liner',
            'unit' => InventoryUnit::Each,
            'status' => InventoryItemStatus::Active,
        ];
    }
}
