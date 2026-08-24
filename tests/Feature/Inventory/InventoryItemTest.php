<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_factory_stamps_organization_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $item = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->assertSame($tenant['org']->id, $item->organization_id);
    }

    public function test_display_name_uses_sku_when_set(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () {
            $withSku = InventoryItem::factory()->create([
                'sku' => 'LINER-6',
                'name' => '6 mil liner',
            ]);
            $withoutSku = InventoryItem::factory()->create([
                'sku' => null,
                'name' => '6 mil liner',
            ]);

            $this->assertSame('LINER-6 · 6 mil liner', $withSku->displayName());
            $this->assertSame('6 mil liner', $withoutSku->displayName());
        });
    }

    public function test_empty_sku_is_stored_as_null_and_a_new_item_is_empty(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () {
            $item = InventoryItem::factory()->create(['sku' => '']);

            $this->assertNull($item->sku);
            $this->assertTrue($item->isEmpty());
        });
    }

    public function test_item_and_location_are_empty_at_zero_qty_and_not_when_qty_moves(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () {
            $item = InventoryItem::factory()->create();
            $location = InventoryLocation::factory()->create();

            InventoryBalance::create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => '0.000',
            ]);

            $this->assertTrue($item->isEmpty());
            $this->assertTrue($location->isEmpty());
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $location->id));

            $item->balances()->first()->update(['qty_on_hand' => '1.5']);

            $this->assertFalse($item->fresh()->isEmpty());
            $this->assertFalse($location->fresh()->isEmpty());
            $this->assertSame('1.500', InventoryBalance::qtyOnHand($item->id, $location->id));
        });
    }

    public function test_qty_on_hand_is_zero_when_no_balance_row_exists(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () {
            $item = InventoryItem::factory()->create();
            $location = InventoryLocation::factory()->create();

            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $location->id));
        });
    }

    public function test_item_is_not_empty_when_it_has_order_or_movement_lines(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () {
            $location = InventoryLocation::factory()->create();
            $otherLocation = InventoryLocation::factory()->create();
            $vendor = InventoryVendor::factory()->create();

            $poItem = InventoryItem::factory()->create();
            $po = InventoryPurchaseOrder::create([
                'vendor_id' => $vendor->id,
                'number' => 'PO-'.bin2hex(random_bytes(4)),
                'status' => InventoryPurchaseOrderStatus::Draft,
            ]);
            InventoryPurchaseOrderLine::create([
                'purchase_order_id' => $po->id,
                'item_id' => $poItem->id,
                'qty_ordered' => '2.000',
            ]);
            $this->assertFalse($poItem->isEmpty());

            $issueItem = InventoryItem::factory()->create();
            $issue = InventoryIssue::create([
                'location_id' => $location->id,
                'note' => 'used at the shop',
                'number' => 'IS-'.bin2hex(random_bytes(4)),
                'issued_on' => '2026-08-24',
            ]);
            InventoryIssueLine::create([
                'issue_id' => $issue->id,
                'item_id' => $issueItem->id,
                'qty' => '1.000',
            ]);
            $this->assertFalse($issueItem->isEmpty());

            $transferItem = InventoryItem::factory()->create();
            $transfer = InventoryTransfer::create([
                'from_location_id' => $location->id,
                'to_location_id' => $otherLocation->id,
                'number' => 'T-'.bin2hex(random_bytes(4)),
                'transferred_on' => '2026-08-24',
            ]);
            InventoryTransferLine::create([
                'transfer_id' => $transfer->id,
                'item_id' => $transferItem->id,
                'qty' => '1.000',
            ]);
            $this->assertFalse($transferItem->isEmpty());

            $adjustItem = InventoryItem::factory()->create();
            InventoryAdjustment::create([
                'location_id' => $location->id,
                'item_id' => $adjustItem->id,
                'qty_before' => '0.000',
                'qty_after' => '1.000',
                'reason' => 'counted',
                'adjusted_on' => '2026-08-24',
            ]);
            $this->assertFalse($adjustItem->isEmpty());
        });
    }
}
