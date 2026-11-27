<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InventoryImportA1Test extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_imports_items_vendor_and_opening_stock_once(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise']->name;
        $file = tempnam(sys_get_temp_dir(), 'a1inv');
        file_put_contents($file, json_encode([
            'items' => [
                ['sku' => 'G4ELB', 'name' => 'Galvanized Elbow', 'size' => '4"', 'unit' => 'each', 'unit_cost_cents' => 925, 'is_active' => 1],
                ['sku' => 'BOC1419', 'name' => 'Bolt On Cap', 'size' => '13.75"X19"', 'unit' => '11', 'unit_cost_cents' => 9500, 'is_active' => 1],
            ],
            'vendors' => ['Lifetime'],
            'stock' => [$branch => ['G4ELB' => 24, 'BOC1419' => 10]],
        ]));
        $args = ['file' => $file, '--company' => $tenant['company']->id, '--as' => $tenant['admin']->email];

        $this->artisan('inventory:import-a1', [...$args, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => InventoryItem::count()));

        $this->artisan('inventory:import-a1', $args)->assertSuccessful();
        $this->artisan('inventory:import-a1', $args)->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($branch) {
            $this->assertSame(2, InventoryItem::count());
            $elbow = InventoryItem::where('sku', 'G4ELB')->firstOrFail();
            $this->assertSame('Galvanized Elbow 4"', $elbow->name);
            $this->assertSame(925, $elbow->last_cost_minor);
            $this->assertSame('each', InventoryItem::where('sku', 'BOC1419')->first()->unit->value);
            $this->assertSame(1, InventoryVendor::count());
            $place = InventoryLocation::where('name', $branch)->sole();
            $this->assertSame('warehouse', $place->kind->value);
            $this->assertSame(34.0, (float) InventoryBalance::where('location_id', $place->id)->sum('qty_on_hand'));
            // Twice run, counted once.
            $this->assertSame(2, InventoryAdjustment::count());
        });
    }
}
