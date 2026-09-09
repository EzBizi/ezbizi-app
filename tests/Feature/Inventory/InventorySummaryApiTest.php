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
 * GET /inventory/summary — the General tab's one request.
 */
class InventorySummaryApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_health_counts_what_is_in_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: 250);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertFalse($health['empty']);
        $this->assertSame(1, $health['items']);
        $this->assertSame(1, $health['low']);
        $this->assertSame(0, $health['out']);
        $this->assertSame(1000, $health['value']['amount_minor']);
        $this->assertSame('USD', $health['value']['currency']);
    }

    public function test_without_financials_there_is_no_value_key_at_all(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $clerk = $this->userWithRole($tenant, 'Clerk', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: 250);

        $health = $this->signedInAs($clerk)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        // Absence, not zero: a zero would be a money answer.
        $this->assertArrayNotHasKey('value', $health);
        $this->assertSame(1, $health['low']);
    }

    public function test_no_items_in_reach_returns_the_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertTrue($health['empty']);
        $this->assertSame('No items in reach.', $health['sentence']);
    }

    public function test_the_low_figure_carries_the_query_that_produced_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: null);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertSame('stock?low=1', $health['links']['low']);
    }

    public function test_a_purchase_order_with_no_ordered_on_is_never_called_old(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $vendor = \App\Models\InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // ordered_on is nullable. Ageing a null from zero would mark every
            // undated order permanently fine, which is the opposite of a warning.
            \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-1',
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => null,
            ]);

            // Control: a dated order old enough to trigger the warning, so the
            // absence above proves the null was excluded and not that the
            // query never fires at all.
            \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-2',
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => now()->subDays(60),
            ]);
        });

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $byKey = array_column($warnings, null, 'key');

        $this->assertArrayHasKey('purchase_order_open_a_long_time', $byKey);
        $this->assertSame(1, $byKey['purchase_order_open_a_long_time']['count']);
    }

    public function test_a_warning_with_nothing_in_it_is_not_sent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '40.000', reorderAt: '10.000', lastCostMinor: 250);

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $this->assertNotContains('below_reorder', array_column($warnings, 'key'));
        $this->assertNotContains('out_of_stock', array_column($warnings, 'key'));
    }

    public function test_out_of_stock_leads_the_list(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '0.000', reorderAt: '10.000', lastCostMinor: 250);
        $this->seedOneItemOnHand($tenant, qty: '2.000', reorderAt: '10.000', lastCostMinor: 250);

        $keys = array_column(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('warnings'),
            'key',
        );

        $this->assertSame('out_of_stock', $keys[0]);
    }

    public function test_no_cost_recorded_is_withheld_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $clerk = $this->userWithRole($tenant, 'Clerk', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: null, lastCostMinor: null);

        $keys = array_column(
            $this->signedInAs($clerk)->getJson('/api/v1/inventory/summary')->assertOk()->json('warnings'),
            'key',
        );

        $this->assertNotContains('no_cost_recorded', $keys);
    }

    private function seedOneItemOnHand(array $tenant, string $qty, ?string $reorderAt, ?int $lastCostMinor): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty, $reorderAt, $lastCostMinor) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => $reorderAt,
                'last_cost_minor' => $lastCostMinor,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => $qty,
            ]);
        });
    }
}
