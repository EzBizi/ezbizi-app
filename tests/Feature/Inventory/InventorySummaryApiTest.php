<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Models\InventorySaleFulfillment;
use App\Models\InventorySaleFulfillmentLine;
use App\Models\InventorySaleLine;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
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

        // low_items, not low: the figure counts items by their total on hand,
        // so the list it opens has to be filtered the same way.
        $this->assertSame('stock?low_items=1', $health['links']['low']);
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

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $this->assertSame('out_of_stock', array_column($warnings, 'key')[0]);

        $byKey = array_column($warnings, null, 'key');

        // No link, and the row beneath it proves that is a decision rather
        // than a response that carries no links at all: an item with nothing
        // anywhere usually has no balance row, and the stock list is over
        // balance rows.
        $this->assertArrayNotHasKey('link', $byKey['out_of_stock']);
        $this->assertSame('stock?low_items=1', $byKey['below_reorder']['link']);
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

    public function test_a_transfer_is_neither_in_nor_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneTransferToday($tenant, qty: '10.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        // The whole point: ten moved between two of our own shelves is not ten
        // received and ten issued, and a chart that says so makes a quiet week
        // look busy.
        $this->assertSame(0.0, array_sum($lines['in']['points']));
        $this->assertSame(0.0, array_sum($lines['out']['points']));
        $this->assertSame(10.0, array_sum($lines['transferred']['points']));
        $this->assertTrue($lines['transferred']['internal']);
    }

    public function test_an_adjustment_downward_is_a_negative_adjustment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneAdjustmentToday($tenant, before: '10.000', after: '4.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(-6.0, array_sum($lines['adjusted']['points']));
    }

    public function test_points_are_index_aligned_with_buckets(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $movement = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('movement');

        foreach ($movement['lines'] as $line) {
            $this->assertCount(
                count($movement['buckets']),
                $line['points'],
                "Line {$line['key']} has a different number of points than there are buckets.",
            );
        }
    }

    public function test_a_sale_fulfilment_counts_as_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneFulfilmentToday($tenant, qty: '3.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(3.0, array_sum($lines['out']['points']));
    }

    /**
     * The dedicated test for the trap named in the task: `out` sums TWO
     * sources (issues and sale fulfilments) that come from unrelated
     * tables. A test that only ever seeds one source cannot tell "both are
     * summed" from "only one is read" — this seeds both together and checks
     * the total is their sum, not either figure alone.
     */
    public function test_out_sums_issues_and_fulfilments_together(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneIssueToday($tenant, qty: '5.000');
        $this->seedOneFulfilmentToday($tenant, qty: '3.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(8.0, array_sum($lines['out']['points']));
    }

    /**
     * A receive: the only movement source whose line carries no item at all.
     *
     * `inventory_receive_lines` has receive_id, purchase_order_line_id and qty
     * — the item is on the purchase-order LINE, one hop further out
     * (database/migrations/2026_08_24_160000_create_inventory_tables.php:101-129).
     * A receive hangs off a purchase order, so the fixture is vendor → order →
     * order line → receive → receive line; there is no shorter way to make one.
     *
     * $item and $into are optional so the visibility test can build a receive
     * that fails ONE of the two gates at a time.
     */
    private function seedOneReceiveToday(
        array $tenant,
        string $qty,
        ?InventoryItem $item = null,
        ?InventoryLocation $into = null,
    ): void {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty, $item, $into) {
            $into ??= InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            $item ??= InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            $vendor = \App\Models\InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            $order = \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-'.uniqid(),
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => now()->toDateString(),
            ]);

            $orderLine = \App\Models\InventoryPurchaseOrderLine::create([
                'organization_id' => $tenant['org']->id,
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'qty_ordered' => $qty,
                'qty_received' => $qty,
            ]);

            $receive = \App\Models\InventoryReceive::create([
                'organization_id' => $tenant['org']->id,
                'purchase_order_id' => $order->id,
                'location_id' => $into->id,
                'received_on' => now()->toDateString(),
            ]);

            \App\Models\InventoryReceiveLine::create([
                'organization_id' => $tenant['org']->id,
                'receive_id' => $receive->id,
                'purchase_order_line_id' => $orderLine->id,
                'qty' => $qty,
            ]);
        });
    }

    public function test_a_receive_counts_as_in(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneReceiveToday($tenant, qty: '7.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        // The figure, not its absence: seven received has to arrive as seven.
        $this->assertSame(7.0, array_sum($lines['in']['points']));

        // And on that line only — a receive is stock arriving, never leaving.
        $this->assertSame(0.0, array_sum($lines['out']['points']));
    }

    /**
     * Reach, tested where the join hops are.
     *
     * `received` reaches its item through the purchase-order line and its
     * place through the receive, so there are two gates and either one alone
     * lets a sibling company's stock through. The two foreign receives here
     * fail one gate each: the first has their item in our shed, the second has
     * our item in theirs. Neither may show, and the ours-only control proves
     * the line can carry a number at all.
     */
    public function test_a_sibling_companys_receive_is_absent_from_movement(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], fn () => \App\Models\Company::create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Beta Co',
            'is_active' => true,
        ]));

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view'],
            onlyAt: $tenant['franchise'],
        );

        [$theirItem, $theirLocation, $ourItem, $ourLocation] = $this->inTenant(
            $tenant['org'],
            fn () => [
                InventoryItem::factory()->create([
                    'organization_id' => $tenant['org']->id,
                    'company_id' => $sibling->id,
                    'status' => InventoryItemStatus::Active,
                ]),
                InventoryLocation::factory()->create([
                    'organization_id' => $tenant['org']->id,
                    'company_id' => $sibling->id,
                ]),
                InventoryItem::factory()->create([
                    'organization_id' => $tenant['org']->id,
                    'company_id' => $tenant['company']->id,
                    'status' => InventoryItemStatus::Active,
                ]),
                InventoryLocation::factory()->create([
                    'organization_id' => $tenant['org']->id,
                    'company_id' => $tenant['company']->id,
                ]),
            ],
        );

        $this->seedOneReceiveToday($tenant, qty: '7.000');
        $this->seedOneReceiveToday($tenant, qty: '100.000', item: $theirItem, into: $ourLocation);
        $this->seedOneReceiveToday($tenant, qty: '200.000', item: $ourItem, into: $theirLocation);

        $lines = collect(
            $this->signedInAs($local)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(7.0, array_sum($lines['in']['points']));

        // Every line, not just `in`: 107, 207 or 307 anywhere in the block
        // would mean a foreign receive found a home on some other series.
        $this->assertSame(
            7.0,
            $lines->sum(fn (array $line) => array_sum($line['points'])),
        );
    }

    public function test_a_period_the_dashboard_does_not_offer_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary?period=nope')
            ->assertStatus(422);

        // Control: one the dashboard does offer, sent the same way. Without it
        // the 422 above could equally mean the endpoint refuses any ?period at
        // all — and nothing else in this file sends one.
        $this->assertSame(
            'week',
            $this->signedInAs($boss)
                ->getJson('/api/v1/inventory/summary?period=week')
                ->assertOk()
                ->json('movement.period'),
        );
    }

    private function seedOneTransferToday(array $tenant, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty) {
            $from = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $to = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            $transfer = \App\Models\InventoryTransfer::create([
                'organization_id' => $tenant['org']->id,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'number' => 'TR-'.uniqid(),
                'transferred_on' => now()->toDateString(),
            ]);

            \App\Models\InventoryTransferLine::create([
                'organization_id' => $tenant['org']->id,
                'transfer_id' => $transfer->id,
                'item_id' => $item->id,
                'qty' => $qty,
            ]);
        });
    }

    private function seedOneAdjustmentToday(array $tenant, string $before, string $after): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $before, $after) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            \App\Models\InventoryAdjustment::create([
                'organization_id' => $tenant['org']->id,
                'location_id' => $location->id,
                'item_id' => $item->id,
                'qty_before' => $before,
                'qty_after' => $after,
                'reason' => 'Counted.',
                'adjusted_on' => now()->toDateString(),
            ]);
        });
    }

    private function seedOneIssueToday(array $tenant, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            $issue = \App\Models\InventoryIssue::create([
                'organization_id' => $tenant['org']->id,
                'location_id' => $location->id,
                'note' => 'Used on site.',
                'number' => 'IS-'.uniqid(),
                'issued_on' => now()->toDateString(),
            ]);

            \App\Models\InventoryIssueLine::create([
                'organization_id' => $tenant['org']->id,
                'issue_id' => $issue->id,
                'item_id' => $item->id,
                'qty' => $qty,
            ]);
        });
    }

    /**
     * `inventory_sales` required columns (organization_id, number, status,
     * payment_status — company_id/franchise_id, customer_id, note and
     * sold_on are all nullable) read from
     * database/migrations/2026_08_25_120000_create_inventory_sales_tables.php:18-34,
     * not invented — an invented fixture only proves the code agrees with
     * itself.
     */
    private function seedOneFulfilmentToday(array $tenant, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            $sale = InventorySale::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'number' => 'SA-'.uniqid(),
                'status' => InventorySaleStatus::Fulfilled,
                'payment_status' => InventorySalePaymentStatus::Paid,
            ]);

            $saleLine = InventorySaleLine::create([
                'organization_id' => $tenant['org']->id,
                'sale_id' => $sale->id,
                'item_id' => $item->id,
                'qty_ordered' => $qty,
                'qty_fulfilled' => $qty,
            ]);

            $fulfillment = InventorySaleFulfillment::create([
                'organization_id' => $tenant['org']->id,
                'sale_id' => $sale->id,
                'location_id' => $location->id,
                'fulfilled_on' => now()->toDateString(),
            ]);

            InventorySaleFulfillmentLine::create([
                'organization_id' => $tenant['org']->id,
                'fulfillment_id' => $fulfillment->id,
                'sale_line_id' => $saleLine->id,
                'qty' => $qty,
            ]);
        });
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
