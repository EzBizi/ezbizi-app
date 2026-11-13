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
 * One inventory value on every tab (UI/UX audit 2026-11-13: $60,846.50 vs
 * $60,845.50). General, Stock and the sum of Locations must be one number,
 * built here from the cases that used to split them: a discontinued item on a
 * shelf, a fractional quantity priced per shelf, and a negative balance.
 */
class InventoryValueAgreesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_general_stock_and_locations_report_one_value(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Money', [
            'inventory.view', 'inventory.general.view', 'inventory.stock.view', 'inventory.items.view',
            'inventory.locations.view', 'inventory.financials',
        ]);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $place = fn ($n) => InventoryLocation::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'name' => $n]);
            $item = fn ($n, $cost, $status = InventoryItemStatus::Active) => InventoryItem::factory()->create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'name' => $n, 'last_cost_minor' => $cost, 'status' => $status]);
            $hold = fn ($i, $p, $q) => InventoryBalance::create(['organization_id' => $tenant['org']->id, 'item_id' => $i->id, 'location_id' => $p->id, 'qty_on_hand' => $q]);

            [$a, $b] = [$place('A'), $place('B')];
            $split = $item('Split', 333);
            $hold($split, $a, '0.500');   // 166.5 -> 167
            $hold($split, $b, '0.500');   // 167; per-item total would be 333
            $hold($item('Old', 100, InventoryItemStatus::Discontinued), $a, '1.000');
            $short = $item('Short', 1000);
            $hold($short, $a, '2.000');
            $hold($short, $b, '-1.000');  // owed, not worth -$10
        });

        $api = $this->signedInAs($user);
        $general = $api->getJson('/api/v1/inventory/summary')->assertOk()->json('health.value.amount_minor');
        $stock = $api->getJson('/api/v1/inventory/stock/items')->assertOk()->json('meta.tiles.value_minor');
        $locations = array_sum(array_column($api->getJson('/api/v1/inventory/locations')->assertOk()->json('data'), 'value_minor'));

        $this->assertSame(167 + 167 + 100 + 2000, $general);
        $this->assertSame($general, $stock);
        $this->assertSame($general, $locations);
    }
}
