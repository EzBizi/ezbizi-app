<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /inventory/stock/items — one row per ITEM, the grouped read that retires
 * the Stock tab's client-side join (UI queue 43, spec from UI/UX 2026-10-01).
 *
 * The contract is `client/lib/screens/inventory/stock_join.dart`, moved to the
 * server so financials and per-tab permission are enforced once instead of
 * re-derived in every list. Two rules from that file are easy to get wrong and
 * each has its own test: an item's STATUS is judged by its total across every
 * place, while its ON-HAND follows the place filter; and the tiles describe the
 * whole catalogue, not the filtered page.
 */
class InventoryStockItemsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ALL = [
        'inventory.view', 'inventory.stock.view', 'inventory.orders.view', 'inventory.financials',
    ];

    public function test_an_item_held_nowhere_is_a_row_and_is_out_of_stock(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->item($tenant, 'Liner', reorderAt: '5.000');

        $rows = $this->read($tenant, self::ALL)->json('data');

        // The stock list is over balance rows, so an item with none was the one
        // thing the old join had to bolt on. Here it is a row like any other.
        $this->assertCount(1, $rows);
        $this->assertSame('out_of_stock', $rows[0]['status']);
        $this->assertSame('0.000', $rows[0]['on_hand']);
        $this->assertSame([], $rows[0]['places']);
    }

    public function test_status_is_judged_by_the_total_across_places(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $van = $this->place($tenant, 'Van');
        $shop = $this->place($tenant, 'Shop');

        // 3 + 3 against a reorder point of 5: one shelf is short, the item is not.
        $split = $this->item($tenant, 'Split', reorderAt: '5.000');
        $this->hold($tenant, $split, $van, '3.000');
        $this->hold($tenant, $split, $shop, '3.000');

        // The control: 2 in total IS below 5, so "below_reorder" is reachable.
        $short = $this->item($tenant, 'Short', reorderAt: '5.000');
        $this->hold($tenant, $short, $van, '2.000');

        $byName = collect($this->read($tenant, self::ALL)->json('data'))
            ->keyBy('item.name');

        $this->assertSame('in_stock', $byName['Split']['status']);
        $this->assertSame('6.000', $byName['Split']['on_hand']);
        $this->assertSame('below_reorder', $byName['Short']['status']);
    }

    public function test_a_place_filter_moves_on_hand_but_not_status(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $van = $this->place($tenant, 'Van');
        $shop = $this->place($tenant, 'Shop');

        $split = $this->item($tenant, 'Split', reorderAt: '5.000');
        $this->hold($tenant, $split, $van, '3.000');
        $this->hold($tenant, $split, $shop, '3.000');

        // Held only in the shop: no row in the van, so filtering by the van
        // must drop it rather than list it at zero.
        $elsewhere = $this->item($tenant, 'Elsewhere', reorderAt: null);
        $this->hold($tenant, $elsewhere, $shop, '9.000');

        $rows = $this->read($tenant, self::ALL, ['location_id' => $van->id])->json('data');

        $this->assertSame(['Split'], array_column(array_column($rows, 'item'), 'name'));
        // On hand is what is IN the van...
        $this->assertSame('3.000', $rows[0]['on_hand']);
        // ...but the van alone does not make the item low: status is the total.
        $this->assertSame('in_stock', $rows[0]['status']);
        // And the fold-out still lists every place, not just the filtered one.
        $this->assertCount(2, $rows[0]['places']);
    }

    public function test_on_order_is_what_is_still_to_arrive_on_open_orders(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant, 'Liner', reorderAt: null);

        $this->order($tenant, 'PO-1', InventoryPurchaseOrderStatus::Open, $item, ordered: '10.000', received: '4.000');
        $this->order($tenant, 'PO-2', InventoryPurchaseOrderStatus::Draft, $item, ordered: '7.000', received: '0.000');

        $row = $this->read($tenant, self::ALL)->json('data.0');

        $this->assertSame('6.000', $row['on_order']);   // 10 ordered, 4 in
        $this->assertSame('7.000', $row['in_draft']);
        $this->assertSame(['PO-1'], $row['order_numbers']); // open only
    }

    public function test_without_orders_view_there_is_no_order_measurement_at_all(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant, 'Liner', reorderAt: '5.000');
        $this->order($tenant, 'PO-1', InventoryPurchaseOrderStatus::Open, $item, ordered: '10.000', received: '0.000');

        $response = $this->read($tenant, ['inventory.view', 'inventory.stock.view']);
        $row = $response->json('data.0');
        $tiles = $response->json('meta.tiles');

        // Null, not "0.000": a zero is a measurement, and this role took none.
        $this->assertNull($row['on_order']);
        $this->assertNull($row['in_draft']);
        $this->assertSame([], $row['order_numbers']);
        $this->assertArrayNotHasKey('covered_by_order', $tiles);
        $this->assertArrayNotHasKey('on_order_items', $tiles);
        $this->assertArrayNotHasKey('open_orders', $tiles);

        // Control: the same fixture DOES measure for a role holding Orders, so
        // the nulls above are the permission and not a broken order join.
        $this->assertSame('10.000', $this->read($tenant, self::ALL)->json('data.0.on_order'));
    }

    public function test_without_financials_no_cost_or_value_is_sent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $item = $this->item($tenant, 'Liner', reorderAt: null, lastCostMinor: 250);
        $this->hold($tenant, $item, $shop, '4.000');

        $response = $this->read($tenant, ['inventory.view', 'inventory.stock.view', 'inventory.orders.view']);

        $this->assertArrayNotHasKey('last_cost_minor', $response->json('data.0.item'));
        $this->assertArrayNotHasKey('value_minor', $response->json('data.0'));
        $this->assertArrayNotHasKey('value_minor', $response->json('meta.tiles'));

        // Control: with financials the same row carries 4 x 250.
        $this->assertSame(1000, $this->read($tenant, self::ALL)->json('data.0.value_minor'));
    }

    public function test_the_tiles_describe_the_whole_catalogue_not_the_filtered_page(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');

        $low = $this->item($tenant, 'Alpha liner', reorderAt: '5.000');
        $this->hold($tenant, $low, $shop, '2.000');
        $this->item($tenant, 'Beta cap', reorderAt: '5.000'); // held nowhere: out

        $all = $this->read($tenant, self::ALL)->json('meta.tiles');
        $searched = $this->read($tenant, self::ALL, ['search' => 'Beta'])->json('meta.tiles');

        $this->assertSame(2, $all['items']);
        $this->assertSame(1, $all['below_reorder']);
        $this->assertSame(1, $all['out_of_stock']);
        $this->assertSame('Beta cap', $all['first_out_of_stock_name']);

        // A search narrows the rows and must not narrow the tiles, or tapping a
        // tile would report a different count from the one it just showed.
        $this->assertSame($all, $searched);
    }

    public function test_a_tile_filter_returns_only_its_rows(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');

        $low = $this->item($tenant, 'Low one', reorderAt: '5.000');
        $this->hold($tenant, $low, $shop, '2.000');
        $this->item($tenant, 'Out one', reorderAt: null);

        $names = fn (string $tile) => array_column(array_column(
            $this->read($tenant, self::ALL, ['tile' => $tile])->json('data'),
            'item',
        ), 'name');

        $this->assertSame(['Low one'], $names('low'));
        $this->assertSame(['Out one'], $names('out'));
    }

    public function test_a_sibling_companys_stock_is_absent(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], fn () => \App\Models\Company::create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Beta Co',
            'is_active' => true,
        ]));

        $mine = $this->item($tenant, 'Mine', reorderAt: null);
        $this->inTenant($tenant['org'], fn () => InventoryItem::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $sibling->id,
            'name' => 'Theirs',
            'status' => InventoryItemStatus::Active,
        ]));

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view'],
            onlyAt: $tenant['franchise'],
        );

        $names = array_column(array_column(
            $this->signedInAs($local)->getJson('/api/v1/inventory/stock/items')->assertOk()->json('data'),
            'item',
        ), 'name');

        // Control first: the caller's own item is there, so an empty list could
        // not be passing this.
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_the_stock_tab_grant_is_what_opens_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->read($tenant, ['inventory.view', 'inventory.stock.view'])->assertOk();
        $this->read($tenant, ['inventory.view', 'inventory.items.view'])->assertForbidden();
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function read(array $tenant, array $permissions, array $query = [])
    {
        $user = $this->userWithRole($tenant, 'R'.uniqid(), $permissions);

        return $this->signedInAs($user)->getJson('/api/v1/inventory/stock/items?'.http_build_query($query));
    }

    private function item(array $tenant, string $name, ?string $reorderAt, ?int $lastCostMinor = null): InventoryItem
    {
        return $this->inTenant($tenant['org'], fn () => InventoryItem::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'status' => InventoryItemStatus::Active,
            'reorder_at' => $reorderAt,
            'last_cost_minor' => $lastCostMinor,
        ]));
    }

    private function place(array $tenant, string $name): InventoryLocation
    {
        return $this->inTenant($tenant['org'], fn () => InventoryLocation::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
        ]));
    }

    private function hold(array $tenant, InventoryItem $item, InventoryLocation $place, string $qty): void
    {
        $this->inTenant($tenant['org'], fn () => InventoryBalance::create([
            'organization_id' => $tenant['org']->id,
            'item_id' => $item->id,
            'location_id' => $place->id,
            'qty_on_hand' => $qty,
        ]));
    }

    private function order(
        array $tenant,
        string $number,
        InventoryPurchaseOrderStatus $status,
        InventoryItem $item,
        string $ordered,
        string $received,
    ): void {
        $this->inTenant($tenant['org'], function () use ($tenant, $number, $status, $item, $ordered, $received) {
            $vendor = InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            $po = InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => $number,
                'status' => $status,
                'ordered_on' => now()->toDateString(),
            ]);

            InventoryPurchaseOrderLine::create([
                'organization_id' => $tenant['org']->id,
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'qty_ordered' => $ordered,
                'qty_received' => $received,
            ]);
        });
    }
}
