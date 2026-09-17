<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Location A → location B in one save. Posted immediately (spec §8.2).
 */
class InventoryTransferTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_transfer_moves_qty_and_refuses_when_from_cannot_cover(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $from, $to] = $this->twoLocations($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 4],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertIsString($created['number'] ?? null);
        $this->assertStringStartsWith('T-', $created['number']);

        $this->inTenant($tenant['org'], function () use ($item, $from, $to) {
            $this->assertSame('6.000', InventoryBalance::qtyOnHand($item->id, $from->id));
            $this->assertSame('4.000', InventoryBalance::qtyOnHand($item->id, $to->id));
            $this->assertSame(0, bccomp(
                bcadd(
                    InventoryBalance::qtyOnHand($item->id, $from->id),
                    InventoryBalance::qtyOnHand($item->id, $to->id),
                    3,
                ),
                '10.000',
                3,
            ));
            $this->assertSame(1, InventoryTransfer::query()->count());
            $this->assertSame(1, InventoryTransferLine::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 7],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is not enough at this location.');

        $this->inTenant($tenant['org'], function () use ($item, $from, $to) {
            $this->assertSame('6.000', InventoryBalance::qtyOnHand($item->id, $from->id));
            $this->assertSame('4.000', InventoryBalance::qtyOnHand($item->id, $to->id));
            $this->assertSame(1, InventoryTransfer::query()->count());
            $this->assertSame(1, InventoryTransferLine::query()->count());
        });
    }

    public function test_transfer_to_sibling_company_location_is_refused(): void
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

        [$item, $from, $foreign] = $this->inTenant($tenant['org'], function () use ($tenant, $sibling) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Own liner',
            ]);
            $from = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Own shop',
            ]);
            $foreign = InventoryLocation::factory()->create([
                'company_id' => $sibling->id,
                'name' => 'Beta warehouse',
            ]);

            return [$item, $from, $foreign];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $foreign->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 4],
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Stock does not move to a company you do not write.');

        $this->inTenant($tenant['org'], function () use ($item, $from, $foreign) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($item->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $foreign->id));
            $this->assertSame(0, InventoryTransfer::query()->count());
        });
    }

    public function test_failed_line_rolls_back_the_whole_post(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$covered, $short, $from, $to] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $from = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Shop A',
            ]);
            $to = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Shop B',
            ]);
            $covered = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Covered liner',
            ]);
            $short = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Short liner',
            ]);

            return [$covered, $short, $from, $to];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $covered->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $short->id,
                'counted_as' => 2,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $covered->id, 'qty' => 4],
                    ['item_id' => $short->id, 'qty' => 5],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is not enough at this location.');

        $this->inTenant($tenant['org'], function () use ($covered, $short, $from, $to) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($covered->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($covered->id, $to->id));
            $this->assertSame('2.000', InventoryBalance::qtyOnHand($short->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($short->id, $to->id));
            $this->assertSame(0, InventoryTransfer::query()->count());
            $this->assertSame(0, InventoryTransferLine::query()->count());
        });
    }

    public function test_discontinued_item_cannot_transfer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $from, $to] = $this->twoLocations($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$item->id, [
                'status' => InventoryItemStatus::Discontinued->value,
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is discontinued, so it cannot go out.');

        $this->inTenant($tenant['org'], function () use ($item, $from, $to) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($item->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $to->id));
            $this->assertSame(0, InventoryTransfer::query()->count());
        });
    }

    public function test_closed_location_cannot_transfer_from_or_onto(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $from, $to] = $this->twoLocations($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/locations/'.$to->id, [
                'status' => 'closed',
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This location is closed.');

        $this->inTenant($tenant['org'], function () use ($from) {
            $from->status = InventoryLocationStatus::Closed;
            $from->save();
        });

        $openTo = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Open cage',
            ]);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $openTo->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This location is closed.');

        $this->inTenant($tenant['org'], function () use ($item, $from, $to, $openTo) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($item->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $to->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $openTo->id));
            $this->assertSame(0, InventoryTransfer::query()->count());
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

        [$ownItem, $foreignItem, $from, $to] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $sibling) {
                $from = InventoryLocation::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own shop',
                ]);
                $to = InventoryLocation::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own cage',
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

                return [$ownItem, $foreignItem, $from, $to];
            },
        );

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $foreignItem->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $foreignItem->id, 'qty' => 4],
                ],
            ])
            ->assertForbidden();

        $this->inTenant($tenant['org'], function () use ($foreignItem, $from, $to) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($foreignItem->id, $from->id));
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($foreignItem->id, $to->id));
            $this->assertSame(0, InventoryTransfer::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $ownItem->id,
                'counted_as' => 10,
                'reason' => 'own count',
            ])
            ->assertCreated();

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $ownItem->id, 'qty' => 4],
                ],
            ])
            ->assertCreated();
    }

    /**
     * @param  array{org: \App\Models\Organization, company: Company, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: InventoryItem, 1: InventoryLocation, 2: InventoryLocation}
     */
    private function twoLocations(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            $from = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Shop A',
            ]);
            $to = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Shop B',
            ]);

            return [$item, $from, $to];
        });
    }
}
