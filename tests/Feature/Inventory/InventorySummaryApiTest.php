<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /inventory/summary — the General tab's one request.
 */
class InventorySummaryApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_the_30_day_cutoff_is_a_calendar_day_in_the_view_timezone(): void
    {
        // 02:00 UTC on the 9th is still 22:00 on the 8th in New York. A bare
        // now() (app.timezone is UTC — config/app.php) reads "today" as the
        // 9th; the view timezone reads it as the 8th. The two disagree about
        // which purchase orders are over 30 days open.
        Carbon::setTestNow(Carbon::parse('2026-09-09 02:00:00', 'UTC'));

        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            app(SettingsResolver::class)->set('timezone', $tenant['org'], 'America/New_York');

            $vendor = \App\Models\InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // Exactly 30 calendar days before "today" in New York
            // (2026-09-08). The cutoff is strict ('<'), so an order exactly
            // 30 days old is not yet "more than 30 days" and must be
            // excluded — but a UTC-dated "today" of the 9th would wrongly
            // include it.
            \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-BOUNDARY',
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => '2026-08-09',
            ]);

            // Control: one calendar day further back — over 30 days under
            // either timezone's "today" — proves the query still fires.
            \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-OVER',
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => '2026-08-08',
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

    public function test_no_cost_recorded_is_shown_with_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'last_cost_minor' => null,
            ]);
        });

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $byKey = array_column($warnings, null, 'key');

        $this->assertArrayHasKey('no_cost_recorded', $byKey);
        $this->assertSame(1, $byKey['no_cost_recorded']['count']);
        $this->assertSame('1 item has no cost recorded.', $byKey['no_cost_recorded']['sentence']);
    }

    public function test_location_holding_nothing_reports_only_the_empty_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            // Empty: no balance rows at all.
            InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // Holding stock — must NOT be counted, proving the clause
            // discriminates rather than always firing.
            $holding = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);
            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $item->id,
                'location_id' => $holding->id,
                'qty_on_hand' => '4.000',
            ]);
        });

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $byKey = array_column($warnings, null, 'key');

        $this->assertArrayHasKey('location_holding_nothing', $byKey);
        $this->assertSame(1, $byKey['location_holding_nothing']['count']);
        $this->assertSame('1 location is holding nothing.', $byKey['location_holding_nothing']['sentence']);
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
