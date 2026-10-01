<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventorySale;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The tile row on Items, Orders and Locations (suggestion 0075, UI/UX spec
 * 2026-10-01): `meta.tiles` plus the filter each tile applies.
 *
 * The lists are paged, so a tile counted on the client would count one page.
 * Every tile here is counted across all pages and before search, and its filter
 * must land on a list whose `meta.total` is the number the tile showed.
 */
class InventoryListTilesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ALL = [
        'inventory.view', 'inventory.items.view', 'inventory.locations.view',
        'inventory.stock.view', 'inventory.orders.view',
    ];

    // ── Items ─────────────────────────────────────────────────────────

    public function test_item_tiles_count_the_whole_catalogue_and_each_filter_lands_on_its_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');

        $this->hold($tenant, $this->item($tenant, 'Plenty', reorderAt: '2'), $shop, '9.000');
        $this->hold($tenant, $this->item($tenant, 'Low', reorderAt: '5'), $shop, '3.000');
        $this->item($tenant, 'Gone', reorderAt: '1');
        $this->item($tenant, 'Never held');
        $this->item($tenant, 'Old', status: InventoryItemStatus::Discontinued);

        $user = $this->userWithRole($tenant, 'Stock', self::ALL);
        $tiles = $this->signedInAs($user)->getJson('/api/v1/inventory/items?per_page=1&search=Plenty')
            ->assertOk()->json('meta.tiles');

        // Across pages and before search: per_page=1 and a search for one item.
        $this->assertSame(
            ['items' => 4, 'below_reorder' => 1, 'out_of_stock' => 2, 'discontinued' => 1],
            $tiles,
        );

        foreach (['stock=below_reorder' => 1, 'stock=out_of_stock' => 2, 'status=discontinued' => 1] as $filter => $count) {
            $this->assertSame(
                $count,
                $this->signedInAs($user)->getJson('/api/v1/inventory/items?'.$filter)->json('meta.total'),
                $filter,
            );
        }
        $names = collect($this->signedInAs($user)->getJson('/api/v1/inventory/items?stock=out_of_stock')->json('data'))
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['Gone', 'Never held'], $names);
    }

    public function test_without_stock_view_the_stock_tiles_are_null_and_their_filter_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->item($tenant, 'Gone');

        $user = $this->userWithRole($tenant, 'Items', ['inventory.view', 'inventory.items.view']);
        $tiles = $this->signedInAs($user)->getJson('/api/v1/inventory/items')->assertOk()->json('meta.tiles');

        $this->assertSame(['items' => 1, 'below_reorder' => null, 'out_of_stock' => null, 'discontinued' => 0], $tiles);
        // A filtered count is the same stock fact the null tile withheld.
        $this->signedInAs($user)->getJson('/api/v1/inventory/items?stock=out_of_stock')->assertForbidden();
    }

    // ── Purchase orders and sales ────────────────────────────────────

    public function test_order_tiles_and_filters(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant, 'Liner');

        $this->order($tenant, 'PO-1', InventoryPurchaseOrderStatus::Open, $item, '5', '0');
        $this->order($tenant, 'PO-2', InventoryPurchaseOrderStatus::Open, $item, '5', '5');
        $this->order($tenant, 'PO-3', InventoryPurchaseOrderStatus::Draft, $item, '5', '0');
        $this->order($tenant, 'PO-4', InventoryPurchaseOrderStatus::Received, $item, '5', '5');

        $user = $this->userWithRole($tenant, 'Orders', self::ALL);
        $tiles = $this->signedInAs($user)->getJson('/api/v1/inventory/purchase-orders?per_page=1')
            ->assertOk()->json('meta.tiles');

        $this->assertSame(['to_receive' => 1, 'open' => 2], $tiles);

        $toReceive = $this->signedInAs($user)->getJson('/api/v1/inventory/purchase-orders?filter=to_receive');
        $this->assertSame(1, $toReceive->json('meta.total'));
        $this->assertSame('PO-1', $toReceive->json('data.0.number'));
        $this->assertSame(2, $this->signedInAs($user)
            ->getJson('/api/v1/inventory/purchase-orders?filter=open')->json('meta.total'));
        $this->signedInAs($user)->getJson('/api/v1/inventory/purchase-orders?filter=nope')->assertUnprocessable();
    }

    public function test_sale_tiles_and_filters(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->sale($tenant, 'S-1', InventorySaleStatus::Open, InventorySalePaymentStatus::Unpaid);
        $this->sale($tenant, 'S-2', InventorySaleStatus::Partial, InventorySalePaymentStatus::Paid);
        $this->sale($tenant, 'S-3', InventorySaleStatus::Fulfilled, InventorySalePaymentStatus::Unpaid);
        // Neither is owed: a draft is not a sale yet, a cancelled one never was.
        $this->sale($tenant, 'S-4', InventorySaleStatus::Draft, InventorySalePaymentStatus::Unpaid);
        $this->sale($tenant, 'S-5', InventorySaleStatus::Cancelled, InventorySalePaymentStatus::Unpaid);

        $user = $this->userWithRole($tenant, 'Orders', self::ALL);
        $tiles = $this->signedInAs($user)->getJson('/api/v1/inventory/sales?per_page=1')->assertOk()->json('meta.tiles');

        $this->assertSame(['open' => 2, 'unpaid' => 2], $tiles);
        $this->assertSame(2, $this->signedInAs($user)->getJson('/api/v1/inventory/sales?filter=open')->json('meta.total'));
        $this->assertSame(2, $this->signedInAs($user)->getJson('/api/v1/inventory/sales?filter=unpaid')->json('meta.total'));
    }

    // ── Locations ─────────────────────────────────────────────────────

    public function test_location_tiles_count_open_places_by_kind_and_each_filter_lands_on_its_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->place($tenant, 'Main', InventoryLocationKind::Warehouse);
        $this->place($tenant, 'Cage', InventoryLocationKind::Cage);
        $this->place($tenant, 'Dock', InventoryLocationKind::Receiving);
        $this->place($tenant, 'Shop', InventoryLocationKind::Shop);
        $this->place($tenant, 'Van 1', InventoryLocationKind::Van);
        $this->place($tenant, 'Odd', InventoryLocationKind::Other);
        $this->place($tenant, 'Old van', InventoryLocationKind::Van, InventoryLocationStatus::Closed);

        $user = $this->userWithRole($tenant, 'Places', self::ALL);
        $meta = $this->signedInAs($user)->getJson('/api/v1/inventory/locations?per_page=1&search=Main')
            ->assertOk()->json('meta');

        $this->assertSame(['places' => 6, 'storage' => 3, 'shops' => 1, 'vans' => 1], $meta['tiles']);
        // Money is withheld, not zero, without financials.
        $this->assertArrayNotHasKey('tiles_value_minor', $meta);

        foreach (['storage' => 3, 'shop' => 1, 'van' => 1] as $kind => $count) {
            $this->assertSame(
                $count,
                $this->signedInAs($user)->getJson('/api/v1/inventory/locations?kind='.$kind)->json('meta.total'),
                $kind,
            );
        }
    }

    public function test_location_tile_values_need_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $main = $this->place($tenant, 'Main', InventoryLocationKind::Warehouse);
        $van = $this->place($tenant, 'Van 1', InventoryLocationKind::Van);
        $this->place($tenant, 'Shop', InventoryLocationKind::Shop);
        $this->hold($tenant, $this->item($tenant, 'A', lastCostMinor: 250), $main, '4.000');
        $this->hold($tenant, $this->item($tenant, 'B', lastCostMinor: 100), $van, '3.000');

        $user = $this->userWithRole($tenant, 'Money', [...self::ALL, 'inventory.financials']);
        $values = $this->signedInAs($user)->getJson('/api/v1/inventory/locations')->assertOk()->json('meta.tiles_value_minor');

        // Shops hold nothing priced: null, not a counted zero.
        $this->assertSame(['places' => 1300, 'storage' => 1000, 'shops' => null, 'vans' => 300], $values);
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function item(
        array $tenant,
        string $name,
        ?string $reorderAt = null,
        InventoryItemStatus $status = InventoryItemStatus::Active,
        ?int $lastCostMinor = null,
    ): InventoryItem {
        return $this->inTenant($tenant['org'], fn () => InventoryItem::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'status' => $status,
            'reorder_at' => $reorderAt,
            'last_cost_minor' => $lastCostMinor,
        ]));
    }

    private function place(
        array $tenant,
        string $name,
        InventoryLocationKind $kind = InventoryLocationKind::Warehouse,
        InventoryLocationStatus $status = InventoryLocationStatus::Active,
    ): InventoryLocation {
        return $this->inTenant($tenant['org'], fn () => InventoryLocation::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'kind' => $kind,
            'status' => $status,
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

    private function sale(
        array $tenant,
        string $number,
        InventorySaleStatus $status,
        InventorySalePaymentStatus $payment,
    ): void {
        $this->inTenant($tenant['org'], fn () => InventorySale::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'number' => $number,
            'status' => $status,
            'payment_status' => $payment,
            'note' => 'Counter sale',
            'sold_on' => now()->toDateString(),
        ]));
    }
}
