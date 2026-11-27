<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Policies\InventoryTransferPolicy;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The full-code audit's Inventory findings (2026-11-27), one test each. */
class InventoryAudit1127Test extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_sold_item_is_refused_not_a_500(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id]);
            $sale = InventorySale::create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'number' => 'S-1', 'status' => InventorySaleStatus::Open, 'payment_status' => InventorySalePaymentStatus::Unpaid, 'note' => 'counter']);
            InventorySaleLine::create(['organization_id' => $tenant['org']->id, 'sale_id' => $sale->id, 'item_id' => $item->id, 'qty_ordered' => '1']);

            return $item;
        });

        $this->signedInAs($tenant['admin'])->deleteJson('/api/v1/inventory/items/'.$item->id)->assertStatus(422);
    }

    public function test_org_stock_may_move_to_a_company_place(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$from, $to] = $this->inTenant($tenant['org'], fn () => [
            InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => null, 'franchise_id' => null]),
            InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id]),
        ]);

        $this->assertTrue((new InventoryTransferPolicy)->create($tenant['admin'], $from, $to)->allowed());
    }

    public function test_low_and_out_count_only_shelves_the_reader_sees(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');
        $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            $item = InventoryItem::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'status' => InventoryItemStatus::Active, 'reorder_at' => '5']);
            $theirs = InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $other->id]);
            InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id]);
            InventoryBalance::create(['organization_id' => $tenant['org']->id, 'item_id' => $item->id, 'location_id' => $theirs->id, 'qty_on_hand' => '9']);
        });
        $local = $this->userWithRole($tenant, 'Local', ['inventory.view', 'inventory.general.view', 'inventory.stock.view'], onlyAt: $tenant['franchise']);

        $health = $this->signedInAs($local)->getJson('/api/v1/inventory/summary')->assertOk()->json('health');

        // 9 sit on a shelf this person cannot see: to them the item is out.
        $this->assertSame(1, $health['out']);
        $this->assertSame(0, $health['in_stock']);
    }
}
