<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryReceive;
use App\Models\InventoryReceiveLine;
use App\Models\InventoryVendor;
use App\Models\Organization;
use App\Models\User;
use App\Support\Inventory\InventoryItemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Vendors, draft→open POs, and receive onto on-hand (spec §8.3).
 */
class InventoryPurchaseTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_stale_open_order_cannot_receive_after_cancellation(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$vendor, $item, $location] = $this->vendorItemLocation($t);
        $po = $this->openOrder($t['admin'], $vendor, $item, $t, 10);
        $this->inTenant($t['org'], function () use ($po, $location, $t) {
            $stale = InventoryPurchaseOrder::findOrFail($po['id']);
            $line = $stale->lines()->firstOrFail();
            InventoryPurchaseOrder::whereKey($stale->id)->update(['status' => 'cancelled']);
            $this->expectException(\Illuminate\Validation\ValidationException::class);
            app(\App\Support\Inventory\InventoryMover::class)->receive(
                $stale, $location, [['line' => $line, 'qty' => '1']], $t['admin'],
            );
        });
    }

    public function test_order_ten_partial_receive_then_over_receive_then_full(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);

        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 10],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $draft['status']);
        $this->assertIsString($draft['number'] ?? null);
        $this->assertStringStartsWith('PO-', $draft['number']);
        $this->assertNotEmpty($draft['lines']);
        $lineId = $draft['lines'][0]['id'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/open')
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 4],
                ],
            ])
            ->assertCreated();

        $this->assertOnHand($tenant, $item->id, $location->id, '4.000');
        $this->assertPoStatus($tenant, $draft['id'], 'open');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 7],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order does not have that many left to receive.');

        $this->assertOnHand($tenant, $item->id, $location->id, '4.000');
        $this->assertPoStatus($tenant, $draft['id'], 'open');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, InventoryReceive::query()->count());
            $this->assertSame(1, InventoryReceiveLine::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 6],
                ],
            ])
            ->assertCreated();

        $this->assertOnHand($tenant, $item->id, $location->id, '10.000');
        $this->assertPoStatus($tenant, $draft['id'], 'received');
    }

    public function test_cancel_after_a_receive_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);

        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);
        $lineId = $po['lines'][0]['id'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 4],
                ],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/cancel')
            ->assertStatus(422);

        $this->assertPoStatus($tenant, $po['id'], 'open');
        $this->assertOnHand($tenant, $item->id, $location->id, '4.000');
    }

    public function test_without_financials_show_omits_unit_cost_and_last_cost(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);

        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 10, 'unit_cost_minor' => 1_250],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1_250, $draft['lines'][0]['unit_cost_minor']);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/open')
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $draft['lines'][0]['id'], 'qty' => 10],
                ],
            ])
            ->assertCreated();

        $viewer = $this->userWithRole($tenant, 'Viewer', ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.financials',
        ]);

        $strippedPo = $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/purchase-orders/'.$draft['id'])
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('unit_cost_minor', $strippedPo);
        $this->assertArrayNotHasKey('last_cost_minor', $strippedPo);
        $this->assertArrayNotHasKey('unit_cost_minor', $strippedPo['lines'][0]);
        $this->assertArrayNotHasKey('last_cost_minor', $strippedPo['lines'][0]);
        if (isset($strippedPo['lines'][0]['item']) && is_array($strippedPo['lines'][0]['item'])) {
            $this->assertArrayNotHasKey('last_cost_minor', $strippedPo['lines'][0]['item']);
        }

        $keptPo = $this->signedInAs($money)
            ->getJson('/api/v1/inventory/purchase-orders/'.$draft['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1_250, $keptPo['lines'][0]['unit_cost_minor']);

        $strippedItem = $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/items/'.$item->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('last_cost_minor', $strippedItem);

        $keptItem = $this->signedInAs($money)
            ->getJson('/api/v1/inventory/items/'.$item->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1_250, $keptItem['last_cost_minor']);
    }

    public function test_receive_into_a_closed_location_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/locations/'.$location->id, [
                'status' => 'closed',
            ])
            ->assertOk();

        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);
        $lineId = $po['lines'][0]['id'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This location is closed.');

        $this->assertOnHand($tenant, $item->id, $location->id, '0.000');
        $this->assertPoStatus($tenant, $po['id'], 'open');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryReceive::query()->count());
        });
    }

    public function test_discontinued_item_cannot_be_ordered(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vendor = InventoryVendor::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Liner supply',
            ]);
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => '6 mil liner',
                'status' => InventoryItemStatus::Discontinued,
            ]);

            return [$vendor, $item];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 10],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is discontinued, so it cannot go out.');

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryPurchaseOrder::query()->count());
        });
    }

    public function test_discontinued_item_cannot_be_received(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);

        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);
        $lineId = $po['lines'][0]['id'];

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$item->id, [
                'status' => InventoryItemStatus::Discontinued->value,
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $lineId, 'qty' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is discontinued, so it cannot go out.');

        $this->assertOnHand($tenant, $item->id, $location->id, '0.000');
        $this->assertPoStatus($tenant, $po['id'], 'open');
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryReceive::query()->count());
        });
    }

    public function test_refuses_an_item_the_caller_cannot_see(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        [$vendor, $ownItem, $foreignItem] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $sibling) {
                $vendor = InventoryVendor::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own vendor',
                ]);
                $ownItem = InventoryItem::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own liner',
                ]);
                $foreignItem = InventoryItem::factory()->create([
                    'company_id' => $sibling->id,
                    'name' => 'Beta liner',
                ]);

                return [$vendor, $ownItem, $foreignItem];
            },
        );

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.create', 'inventory.edit'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $foreignItem->id, 'qty' => 10],
                ],
            ])
            ->assertForbidden();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryPurchaseOrder::query()->count());
            $this->assertSame(0, InventoryPurchaseOrderLine::query()->count());
        });

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $ownItem->id, 'qty' => 10],
                ],
            ])
            ->assertCreated();
    }

    public function test_franchise_user_cannot_file_a_vendor_on_a_sibling_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.create'],
            onlyAt: $tenant['franchise'],
        );

        $message = $this->signedInAs($local)
            ->postJson('/api/v1/inventory/vendors', [
                'company_id' => $sibling->id,
                'name' => 'Beta supply',
            ])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('assigned', $message);

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/vendors', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Own supply',
            ])
            ->assertCreated();
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant
     * @return array{0: InventoryVendor, 1: InventoryItem, 2: InventoryLocation}
     */
    private function vendorItemLocation(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $vendor = InventoryVendor::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Liner supply',
            ]);
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => '6 mil liner',
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Receiving',
            ]);

            return [$vendor, $item, $location];
        });
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant
     * @return array<string, mixed>
     */
    private function openOrder(
        User $admin,
        InventoryVendor $vendor,
        InventoryItem $item,
        array $tenant,
        int $qty,
    ): array {
        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => $qty],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/open')
            ->assertOk();

        $draft['status'] = 'open';

        return $draft;
    }

    /** @param  array{org: Organization}  $tenant */
    private function assertOnHand(array $tenant, string $itemId, string $locationId, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($itemId, $locationId, $qty) {
            $this->assertSame($qty, InventoryBalance::qtyOnHand($itemId, $locationId));
        });
    }

    /** @param  array{org: Organization}  $tenant */
    private function assertPoStatus(array $tenant, string $poId, string $status): void
    {
        $this->inTenant($tenant['org'], function () use ($poId, $status) {
            $po = InventoryPurchaseOrder::query()->whereKey($poId)->firstOrFail();
            $this->assertSame($status, $po->status->value);
        });
    }

    /** The search bar: `q` narrows by vendor or item; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$vendor, $item] = $this->vendorItemLocation($t);
        [$brickyard, $cap] = $this->inTenant($t['org'], fn () => [
            InventoryVendor::factory()->create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'name' => 'Brickyard']),
            InventoryItem::factory()->create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'name' => 'Chimney cap']),
        ]);
        $this->openOrder($t['admin'], $vendor, $item, $t, 2);
        $this->openOrder($t['admin'], $brickyard, $cap, $t, 1);

        $url = '/api/v1/inventory/purchase-orders';
        $this->signedInAs($t['admin'])->getJson($url.'?q=brickyard')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.tiles', fn ($tiles) => is_array($tiles));
        $this->signedInAs($t['admin'])->getJson($url.'?q=6%20mil')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($t['admin'])->getJson($url.'?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($t['admin'])->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }
}
