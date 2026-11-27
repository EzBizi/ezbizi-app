<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryItemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The item record and the Stock list must agree about one item's shelves.
 * Prod shape from the A1 import (2026-11-03): a company-level item held in
 * warehouses that sit on two different branches.
 */
class InventoryItemPaneReadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_record_finds_the_shelves_the_list_counted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');
        $user = $this->userWithRole($tenant, 'Stock', ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.locations.view']);

        [$item, $a, $b] = $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id,
                'franchise_id' => null, 'status' => InventoryItemStatus::Active, 'name' => 'Top Plate',
            ]);
            $a = InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id, 'name' => 'Illinois']);
            $b = InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $other->id, 'name' => 'Ohio']);
            foreach ([$a, $b] as $place) {
                InventoryBalance::create(['organization_id' => $tenant['org']->id, 'item_id' => $item->id, 'location_id' => $place->id, 'qty_on_hand' => '4.000']);
            }

            return [$item, $a, $b];
        });

        $list = $this->signedInAs($user)->getJson('/api/v1/inventory/stock/items')->assertOk()->json('data.0');
        $this->assertCount(2, $list['places']);

        $record = $this->signedInAs($user)->getJson('/api/v1/inventory/stock?item_id='.$item->id)->assertOk()->json('data');
        $this->assertCount(2, $record);
        $this->assertSame($item->id, $record[0]['item']['id']);

        $show = $this->signedInAs($user)->getJson('/api/v1/inventory/items/'.$item->id)->assertOk()->json('data');
        $this->assertSame($tenant['company']->name, $show['company']['name']);
    }
}
