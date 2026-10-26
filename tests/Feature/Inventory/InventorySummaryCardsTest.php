<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The General tab's Fleet-style cards (UI/UX, 2026-10-26): by_location,
 * top_items, movement.previous and the open/draft purchase-order split.
 * Money only with `inventory.financials`, absent — not zero — without it.
 */
class InventorySummaryCardsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const VIEW = ['inventory.view', 'inventory.general.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_by_location_counts_items_held_and_values_them_with_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $van = $this->place($tenant, 'Van');
        $this->place($tenant, 'Empty');
        $this->hold($tenant, $this->item($tenant, 'A', 250), $shop, '4.000');
        $this->hold($tenant, $this->item($tenant, 'B', 100), $shop, '3.000');
        $this->hold($tenant, $this->item($tenant, 'C'), $van, '2.000');

        $plain = collect($this->summary($tenant, self::VIEW)['by_location'])->keyBy('location.name');
        $this->assertSame(2, $plain['Shop']['items']);
        $this->assertSame(1, $plain['Van']['items']);
        $this->assertSame(0, $plain['Empty']['items']);
        $this->assertArrayNotHasKey('value_minor', $plain['Shop']);

        $priced = $this->summary($tenant, [...self::VIEW, 'inventory.financials'])['by_location'];
        // Most valuable first; nothing priced is null, never 0.
        $this->assertSame(['Shop', 'Van', 'Empty'], array_column(array_column($priced, 'location'), 'name'));
        $this->assertSame([1300, null, null], array_column($priced, 'value_minor'));
    }

    public function test_top_items_are_the_five_most_valuable_with_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $van = $this->place($tenant, 'Van');
        foreach ([1, 2, 3, 4, 5, 6] as $n) {
            $this->hold($tenant, $this->item($tenant, "I{$n}", $n * 100), $shop, '1.000');
        }
        // Summed across places: 2 x 50 + 1 x 50 = 150, below I2's 200.
        $split = $this->item($tenant, 'Split', 50);
        $this->hold($tenant, $split, $shop, '2.000');
        $this->hold($tenant, $split, $van, '1.000');

        $top = $this->summary($tenant, [...self::VIEW, 'inventory.financials'])['top_items'];

        $this->assertSame(['I6', 'I5', 'I4', 'I3', 'I2'], array_column(array_column($top, 'item'), 'name'));
        $this->assertSame([600, 500, 400, 300, 200], array_column($top, 'value_minor'));
        $this->assertSame('1.000', $top[0]['qty']);
    }

    public function test_without_financials_top_items_rank_by_quantity_and_carry_no_value(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $this->hold($tenant, $this->item($tenant, 'Cheap many', 1), $shop, '9.000');
        $this->hold($tenant, $this->item($tenant, 'Dear few', 9999), $shop, '1.000');

        $top = $this->summary($tenant, self::VIEW)['top_items'];

        $this->assertSame(['Cheap many', 'Dear few'], array_column(array_column($top, 'item'), 'name'));
        $this->assertArrayNotHasKey('value_minor', $top[0]);
    }

    public function test_movement_previous_totals_the_same_span_last_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:00:00', 'UTC'));
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $item = $this->item($tenant, 'A');

        // Last month, inside the same span (1st to 15th): counts.
        $this->adjust($tenant, $item, $shop, '2026-08-10', '10.000', '4.000');
        // Last month but after the 15th: outside the span.
        $this->adjust($tenant, $item, $shop, '2026-08-20', '4.000', '1.000');
        // This month: in the current lines, not in previous.
        $this->adjust($tenant, $item, $shop, '2026-09-05', '1.000', '3.000');

        $movement = $this->summary($tenant, self::VIEW)['movement'];

        $this->assertSame(
            ['received' => 0.0, 'issued_sold' => 0.0, 'adjusted' => -6.0, 'moved' => 0.0],
            $movement['previous'],
        );
    }

    public function test_purchase_orders_split_open_from_draft(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->order($tenant, InventoryPurchaseOrderStatus::Open);
        $this->order($tenant, InventoryPurchaseOrderStatus::Open);
        $this->order($tenant, InventoryPurchaseOrderStatus::Draft);
        $this->order($tenant, InventoryPurchaseOrderStatus::Received);
        $this->place($tenant, 'Shop');

        $health = $this->summary($tenant, self::VIEW)['health'];

        $this->assertSame(['open' => 2, 'draft' => 1], $health['purchase_orders']);
        // Unchanged for clients that read it: open and draft together.
        $this->assertSame(3, $health['open_purchase_orders']);
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function summary(array $tenant, array $permissions): array
    {
        $user = $this->userWithRole($tenant, 'R'.uniqid(), $permissions);

        return $this->signedInAs($user)->getJson('/api/v1/inventory/summary?period=month')->assertOk()->json();
    }

    private function item(array $tenant, string $name, ?int $lastCostMinor = null): InventoryItem
    {
        return $this->inTenant($tenant['org'], fn () => InventoryItem::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'status' => InventoryItemStatus::Active,
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

    private function adjust(array $tenant, InventoryItem $item, InventoryLocation $place, string $on, string $before, string $after): void
    {
        $this->inTenant($tenant['org'], fn () => InventoryAdjustment::create([
            'organization_id' => $tenant['org']->id,
            'item_id' => $item->id,
            'location_id' => $place->id,
            'qty_before' => $before,
            'qty_after' => $after,
            'reason' => 'count',
            'adjusted_on' => $on,
        ]));
    }

    private function order(array $tenant, InventoryPurchaseOrderStatus $status): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $status) {
            $vendor = InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-'.uniqid(),
                'status' => $status,
                'ordered_on' => '2026-09-01',
            ]);
        });
    }
}
