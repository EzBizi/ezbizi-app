<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryReceive;
use App\Models\InventoryReceiveLine;
use App\Models\InventorySale;
use App\Models\InventorySaleFulfillment;
use App\Models\InventorySaleFulfillmentLine;
use App\Models\InventorySaleLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /inventory/items/{item}/movements — an item's History (UI queue 43,
 * gallery 1 "An item open"; spec from UI/UX 2026-10-01).
 *
 * Five movement tables, each shaped differently: receives and sale fulfilments
 * reach their item through a join hop, adjustments carry a before and after
 * rather than a quantity, and a transfer touches two places. This reads all
 * five into one list, newest first, limited to places the caller can see.
 */
class InventoryMovementsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const STOCK = ['inventory.view', 'inventory.stock.view'];

    public function test_every_kind_appears_with_its_sign_and_its_reference(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $van = $this->place($tenant, 'Van');
        $shop = $this->place($tenant, 'Shop');
        $item = $this->item($tenant);

        $this->receive($tenant, $item, $shop, '10.000', 'PO-00029', '2026-09-20');
        $this->issue($tenant, $item, $van, '1.000', '2026-09-30');
        $this->transfer($tenant, $item, $shop, $van, '2.000', 'T-00017', '2026-09-24');
        $this->adjust($tenant, $item, $shop, before: '8.000', after: '7.000', on: '2026-09-25');
        $this->sell($tenant, $item, $shop, '3.000', 'SO-00013', '2026-09-26');

        $byKind = collect($this->read($tenant, $item, self::STOCK))->keyBy('kind');

        $this->assertSame('10.000', $byKind['received']['qty']);
        $this->assertSame(['type' => 'purchase_order', 'number' => 'PO-00029'], $this->ref($byKind['received']));

        $this->assertSame('-1.000', $byKind['issued']['qty']);
        $this->assertSame('Van', $byKind['issued']['location']['name']);

        // A transfer is the amount moved, from one place to another.
        $this->assertSame('2.000', $byKind['transferred']['qty']);
        $this->assertSame('Shop', $byKind['transferred']['location']['name']);
        $this->assertSame('Van', $byKind['transferred']['to_location']['name']);
        $this->assertSame(['type' => 'transfer', 'number' => 'T-00017'], $this->ref($byKind['transferred']));

        // An adjustment is after minus before, and its reason is the note.
        $this->assertSame('-1.000', $byKind['adjusted']['qty']);
        $this->assertSame('Counted.', $byKind['adjusted']['note']);

        $this->assertSame('-3.000', $byKind['sold']['qty']);
        $this->assertSame(['type' => 'sale', 'number' => 'SO-00013'], $this->ref($byKind['sold']));
    }

    public function test_newest_first(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $item = $this->item($tenant);

        $this->adjust($tenant, $item, $shop, before: '0.000', after: '1.000', on: '2026-09-01');
        $this->adjust($tenant, $item, $shop, before: '1.000', after: '5.000', on: '2026-09-15');
        $this->adjust($tenant, $item, $shop, before: '5.000', after: '4.000', on: '2026-09-08');

        $dates = array_column($this->read($tenant, $item, self::STOCK), 'occurred_at');

        $this->assertSame(['2026-09-15', '2026-09-08', '2026-09-01'], $dates);
    }

    public function test_a_movement_at_a_place_the_caller_cannot_open_is_absent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');
        $item = $this->item($tenant);

        $mine = $this->place($tenant, 'Mine', $tenant['franchise']->id);
        $theirs = $this->place($tenant, 'Theirs', $other->id);
        $this->adjust($tenant, $item, $mine, before: '0.000', after: '2.000', on: '2026-09-01');
        $this->adjust($tenant, $item, $theirs, before: '0.000', after: '9.000', on: '2026-09-02');

        $local = $this->userWithRole($tenant, 'Local', self::STOCK, onlyAt: $tenant['franchise']);
        $rows = $this->signedInAs($local)
            ->getJson("/api/v1/inventory/items/{$item->id}/movements")
            ->assertOk()->json('data');

        // Control first: the caller's own movement is there.
        $names = array_column(array_column($rows, 'location'), 'name');
        $this->assertSame(['Mine'], $names);
    }

    public function test_a_transfer_from_a_hidden_place_keeps_the_row_and_hides_the_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');
        $item = $this->item($tenant);

        $theirs = $this->place($tenant, 'Theirs', $other->id);
        $mine = $this->place($tenant, 'Mine', $tenant['franchise']->id);
        $this->transfer($tenant, $item, $theirs, $mine, '4.000', 'T-00001', '2026-09-03');

        $local = $this->userWithRole($tenant, 'Local', self::STOCK, onlyAt: $tenant['franchise']);
        $rows = $this->signedInAs($local)
            ->getJson("/api/v1/inventory/items/{$item->id}/movements")
            ->assertOk()->json('data');

        // Dropping the row would make four units appear in "Mine" from nowhere;
        // naming "Theirs" would leak a place this person cannot open.
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['location']);
        $this->assertSame('Mine', $rows[0]['to_location']['name']);
    }

    public function test_a_receives_unit_cost_needs_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $shop = $this->place($tenant, 'Shop');
        $item = $this->item($tenant);
        $this->receive($tenant, $item, $shop, '10.000', 'PO-1', '2026-09-20', unitCostMinor: 450);

        $this->assertArrayNotHasKey('unit_cost_minor', $this->read($tenant, $item, self::STOCK)[0]);

        $priced = $this->read($tenant, $item, [...self::STOCK, 'inventory.financials'])[0];
        $this->assertSame(450, $priced['unit_cost_minor']);
    }

    public function test_the_stock_tab_grant_is_what_opens_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $item = $this->item($tenant);

        $this->readRaw($tenant, $item, self::STOCK)->assertOk();
        $this->readRaw($tenant, $item, ['inventory.view', 'inventory.items.view'])->assertForbidden();
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function ref(array $row): ?array
    {
        return $row['reference'] === null ? null : [
            'type' => $row['reference']['type'],
            'number' => $row['reference']['number'],
        ];
    }

    private function readRaw(array $tenant, InventoryItem $item, array $permissions)
    {
        $user = $this->userWithRole($tenant, 'R'.uniqid(), $permissions);

        return $this->signedInAs($user)->getJson("/api/v1/inventory/items/{$item->id}/movements");
    }

    private function read(array $tenant, InventoryItem $item, array $permissions): array
    {
        return $this->readRaw($tenant, $item, $permissions)->assertOk()->json('data');
    }

    private function item(array $tenant): InventoryItem
    {
        return $this->inTenant($tenant['org'], fn () => InventoryItem::factory()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'status' => InventoryItemStatus::Active,
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

    private function receive(array $tenant, InventoryItem $item, InventoryLocation $at, string $qty, string $number, string $on, ?int $unitCostMinor = null): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $item, $at, $qty, $number, $on, $unitCostMinor) {
            $vendor = InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $po = InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => $number,
                'status' => InventoryPurchaseOrderStatus::Open,
                'ordered_on' => $on,
            ]);
            $line = InventoryPurchaseOrderLine::create([
                'organization_id' => $tenant['org']->id,
                'purchase_order_id' => $po->id,
                'item_id' => $item->id,
                'qty_ordered' => $qty,
                'qty_received' => $qty,
                'unit_cost_minor' => $unitCostMinor,
            ]);
            $receive = InventoryReceive::create([
                'organization_id' => $tenant['org']->id,
                'purchase_order_id' => $po->id,
                'location_id' => $at->id,
                'received_on' => $on,
            ]);
            InventoryReceiveLine::create([
                'organization_id' => $tenant['org']->id,
                'receive_id' => $receive->id,
                'purchase_order_line_id' => $line->id,
                'qty' => $qty,
            ]);
        });
    }

    private function issue(array $tenant, InventoryItem $item, InventoryLocation $at, string $qty, string $on): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $item, $at, $qty, $on) {
            $issue = InventoryIssue::create([
                'organization_id' => $tenant['org']->id,
                'location_id' => $at->id,
                'note' => 'For the Smith job.',
                'number' => 'ISS-'.uniqid(),
                'issued_on' => $on,
            ]);
            InventoryIssueLine::create([
                'organization_id' => $tenant['org']->id,
                'issue_id' => $issue->id,
                'item_id' => $item->id,
                'qty' => $qty,
            ]);
        });
    }

    private function transfer(array $tenant, InventoryItem $item, InventoryLocation $from, InventoryLocation $to, string $qty, string $number, string $on): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $item, $from, $to, $qty, $number, $on) {
            $transfer = InventoryTransfer::create([
                'organization_id' => $tenant['org']->id,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'number' => $number,
                'transferred_on' => $on,
            ]);
            InventoryTransferLine::create([
                'organization_id' => $tenant['org']->id,
                'transfer_id' => $transfer->id,
                'item_id' => $item->id,
                'qty' => $qty,
            ]);
        });
    }

    private function adjust(array $tenant, InventoryItem $item, InventoryLocation $at, string $before, string $after, string $on): void
    {
        $this->inTenant($tenant['org'], fn () => InventoryAdjustment::create([
            'organization_id' => $tenant['org']->id,
            'location_id' => $at->id,
            'item_id' => $item->id,
            'qty_before' => $before,
            'qty_after' => $after,
            'reason' => 'Counted.',
            'adjusted_on' => $on,
        ]));
    }

    private function sell(array $tenant, InventoryItem $item, InventoryLocation $at, string $qty, string $number, string $on): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $item, $at, $qty, $number, $on) {
            $sale = InventorySale::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'number' => $number,
                'status' => InventorySaleStatus::Open,
                'payment_status' => InventorySalePaymentStatus::Unpaid,
            ]);
            $saleLine = InventorySaleLine::create([
                'organization_id' => $tenant['org']->id,
                'sale_id' => $sale->id,
                'item_id' => $item->id,
                'qty_ordered' => $qty,
            ]);
            $fulfillment = InventorySaleFulfillment::create([
                'organization_id' => $tenant['org']->id,
                'sale_id' => $sale->id,
                'location_id' => $at->id,
                'fulfilled_on' => $on,
            ]);
            InventorySaleFulfillmentLine::create([
                'organization_id' => $tenant['org']->id,
                'fulfillment_id' => $fulfillment->id,
                'sale_line_id' => $saleLine->id,
                'qty' => $qty,
            ]);
        });
    }
}
