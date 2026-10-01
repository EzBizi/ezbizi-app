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
 * The totals the Items and Locations lists used to join on the client (UI queue
 * 43): `on_hand` on `/inventory/items`, `holds` and `value_minor` on
 * `/inventory/locations`.
 *
 * Both endpoints are ungated reference data, so these figures carry their own
 * rule: they are STOCK facts, measured only for a role holding
 * `inventory.stock.view`, and null — not zero — for one that does not. A role
 * granted Items or Locations without Stock was told "not the quantities".
 */
class InventoryListTotalsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const STOCK = ['inventory.view', 'inventory.items.view', 'inventory.locations.view', 'inventory.stock.view'];

    public function test_an_item_reports_its_total_across_places(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant, 'Split');
        $this->hold($tenant, $item, $this->place($tenant, 'Van'), '3.000');
        $this->hold($tenant, $item, $this->place($tenant, 'Shop'), '3.000');
        $this->item($tenant, 'Nowhere');

        $byName = collect($this->items($tenant, self::STOCK))->keyBy('name');

        $this->assertSame('6.000', $byName['Split']['on_hand']);
        // Held nowhere is a measured zero, for a role allowed to measure.
        $this->assertSame('0.000', $byName['Nowhere']['on_hand']);
    }

    public function test_without_stock_view_an_item_has_no_on_hand(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant, 'Liner');
        $this->hold($tenant, $item, $this->place($tenant, 'Shop'), '4.000');

        $row = $this->items($tenant, ['inventory.view', 'inventory.items.view'])[0];

        $this->assertArrayHasKey('on_hand', $row);
        $this->assertNull($row['on_hand']);

        // Control: the same fixture measures 4 for a role holding Stock.
        $this->assertSame('4.000', $this->items($tenant, self::STOCK)[0]['on_hand']);
    }

    public function test_a_shelf_the_caller_cannot_open_does_not_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');

        $item = $this->item($tenant, 'Liner'); // company-level, visible to both
        $this->hold($tenant, $item, $this->place($tenant, 'Mine', $tenant['franchise']->id), '3.000');
        $this->hold($tenant, $item, $this->place($tenant, 'Theirs', $other->id), '3.000');

        $local = $this->userWithRole($tenant, 'Local', self::STOCK, onlyAt: $tenant['franchise']);

        $row = $this->signedInAs($local)->getJson('/api/v1/inventory/items')->assertOk()->json('data.0');

        // 3, not 6: the same rule as low_items and the grouped read. A shelf
        // this person cannot see must not make the item look stocked.
        $this->assertSame('3.000', $row['on_hand']);
    }

    public function test_a_location_holds_the_items_with_something_there(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $this->hold($tenant, $this->item($tenant, 'A'), $shop, '2.000');
        $this->hold($tenant, $this->item($tenant, 'B'), $shop, '5.000');
        // A row at zero is a place that once held it, not one that holds it now.
        $this->hold($tenant, $this->item($tenant, 'C'), $shop, '0.000');

        $row = collect($this->locations($tenant, self::STOCK))->firstWhere('name', 'Shop');

        $this->assertSame(2, $row['holds']);
    }

    public function test_without_stock_view_a_location_holds_nothing_measured(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $this->hold($tenant, $this->item($tenant, 'A'), $shop, '2.000');

        $row = $this->locations($tenant, ['inventory.view', 'inventory.locations.view'])[0];

        $this->assertArrayHasKey('holds', $row);
        $this->assertNull($row['holds']);
        $this->assertSame(1, $this->locations($tenant, self::STOCK)[0]['holds']);
    }

    public function test_a_locations_value_needs_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $this->hold($tenant, $this->item($tenant, 'A', lastCostMinor: 250), $shop, '4.000');
        $this->hold($tenant, $this->item($tenant, 'B', lastCostMinor: 100), $shop, '3.000');

        // Without financials the key is absent: withheld, not zero.
        $this->assertArrayNotHasKey('value_minor', $this->locations($tenant, self::STOCK)[0]);

        // With it: 4 x 250 + 3 x 100.
        $priced = $this->locations($tenant, [...self::STOCK, 'inventory.financials'])[0];
        $this->assertSame(1300, $priced['value_minor']);
    }

    public function test_a_location_with_nothing_priced_has_no_value_rather_than_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->hold($tenant, $this->item($tenant, 'A'), $this->place($tenant, 'Shop'), '4.000');

        $row = $this->locations($tenant, [...self::STOCK, 'inventory.financials'])[0];

        $this->assertArrayHasKey('value_minor', $row);
        $this->assertNull($row['value_minor']);
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function items(array $tenant, array $permissions): array
    {
        $user = $this->userWithRole($tenant, 'R'.uniqid(), $permissions);

        return $this->signedInAs($user)->getJson('/api/v1/inventory/items')->assertOk()->json('data');
    }

    private function locations(array $tenant, array $permissions): array
    {
        $user = $this->userWithRole($tenant, 'R'.uniqid(), $permissions);

        return $this->signedInAs($user)->getJson('/api/v1/inventory/locations')->assertOk()->json('data');
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

    private function place(array $tenant, string $name, ?string $franchiseId = null): InventoryLocation
    {
        return $this->inTenant($tenant['org'], fn () => InventoryLocation::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $franchiseId,
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
}
