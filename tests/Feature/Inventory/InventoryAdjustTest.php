<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Counted-as posts on-hand and writes history. Stock lists visible balances only.
 */
class InventoryAdjustTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_adjust_sets_on_hand_and_writes_history(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => '6 mil liner',
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Main shop',
            ]);

            return [$item, $location];
        });

        $payload = [
            'location_id' => $location->id,
            'item_id' => $item->id,
            'counted_as' => 10,
            'reason' => 'opening count',
        ];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', $payload)
            ->assertCreated();

        $listed = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->json('data');

        $row = $this->stockRow($listed, $item->id, $location->id);
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row['qty_on_hand'], '10', 3));

        $this->inTenant($tenant['org'], function () use ($item, $location, $admin) {
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($item->id, $location->id));

            $history = InventoryAdjustment::query()
                ->where('item_id', $item->id)
                ->where('location_id', $location->id)
                ->get();

            $this->assertCount(1, $history);
            $this->assertSame('0.000', (string) $history[0]->qty_before);
            $this->assertSame('10.000', (string) $history[0]->qty_after);
            $this->assertSame('opening count', $history[0]->reason);
            $this->assertSame($admin->id, $history[0]->user_id);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['counted_as']);

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, InventoryAdjustment::query()->count());
            $this->assertSame(1, InventoryBalance::query()->count());
        });
    }

    public function test_empty_reason_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$item, $location] = $this->place($tenant);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 4,
                'reason' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryAdjustment::query()->count());
            $this->assertSame(0, InventoryBalance::query()->count());
        });
    }

    public function test_counted_as_must_be_at_least_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$item, $location] = $this->place($tenant);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => -1,
                'reason' => 'overshot',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['counted_as']);

        $this->inTenant($tenant['org'], function () use ($item, $location) {
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $location->id));
            $this->assertSame(0, InventoryAdjustment::query()->count());
        });
    }

    public function test_low_filter_is_on_hand_at_or_below_reorder(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$lowItem, $okItem, $unsetItem, $location] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Cage',
            ]);
            $lowItem = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Low liner',
                'reorder_at' => '4.000',
            ]);
            $okItem = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Plenty liner',
                'reorder_at' => '4.000',
            ]);
            $unsetItem = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'No reorder',
                'reorder_at' => null,
            ]);

            return [$lowItem, $okItem, $unsetItem, $location];
        });

        foreach ([
            [$lowItem, 3],
            [$okItem, 10],
            [$unsetItem, 1],
        ] as [$item, $qty]) {
            $this->signedInAs($admin)
                ->postJson('/api/v1/inventory/adjustments', [
                    'location_id' => $location->id,
                    'item_id' => $item->id,
                    'counted_as' => $qty,
                    'reason' => 'count',
                ])
                ->assertCreated();
        }

        $all = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $all);

        $low = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock?low=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $low);
        $this->assertSame($lowItem->id, $low[0]['item']['id'] ?? $low[0]['item_id'] ?? null);
    }

    /**
     * `low` and `low_items` answer different questions, and both are wanted.
     *
     * The split item is the whole reason the second filter exists: three in
     * the cage and three in the van is two rows at or below a reorder point of
     * five, and six on hand against it — low to whoever restocks a shelf,
     * fine to whoever writes the purchase order. The General tab's low figure
     * counts items, so its link has to be the one that agrees with it.
     */
    public function test_low_items_asks_of_the_item_what_low_asks_of_the_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$split, $short] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $at = fn (string $name) => InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => $name,
            ]);

            $cage = $at('Cage');
            $van = $at('Van');

            $split = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Split liner',
                'reorder_at' => '5.000',
            ]);
            $short = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Short liner',
                'reorder_at' => '5.000',
            ]);

            foreach ([[$split, $cage, '3.000'], [$split, $van, '3.000'], [$short, $cage, '2.000']] as [$item, $place, $qty]) {
                InventoryBalance::create([
                    'organization_id' => $tenant['org']->id,
                    'item_id' => $item->id,
                    'location_id' => $place->id,
                    'qty_on_hand' => $qty,
                ]);
            }

            return [$split, $short];
        });

        $low = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock?low=1')
            ->assertOk()
            ->json('data');

        // Per row: both halves of the split, and the short one.
        $this->assertSame(
            [$short->id, $split->id, $split->id],
            array_column(array_column($low, 'item'), 'id'),
        );

        $lowItems = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock?low_items=1')
            ->assertOk()
            ->json('data');

        // Per item: six on hand is above the point, so the split one is gone —
        // and the short one is still here, so the filter is not simply empty.
        $this->assertSame(
            [$short->id],
            array_column(array_column($lowItems, 'item'), 'id'),
        );
    }

    public function test_low_items_counts_a_shelf_the_caller_cannot_see_as_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $other = $this->extraFranchise($tenant, 'Alpha F2');

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view'],
            onlyAt: $tenant['franchise'],
        );

        $item = $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            $mine = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Mine',
            ]);
            $theirs = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $other->id,
                'name' => 'Theirs',
            ]);

            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'name' => 'General liner',
                'reorder_at' => '5.000',
            ]);

            // 3 here, 3 at a franchise this user cannot open. Summed over
            // every row it is 6 and not low; summed over what this caller can
            // see it is 3 and low. The filter must answer for the caller.
            foreach ([[$mine, '3.000'], [$theirs, '3.000']] as [$place, $qty]) {
                InventoryBalance::create([
                    'organization_id' => $tenant['org']->id,
                    'item_id' => $item->id,
                    'location_id' => $place->id,
                    'qty_on_hand' => $qty,
                ]);
            }

            return $item;
        });

        $rows = $this->signedInAs($local)
            ->getJson('/api/v1/inventory/stock?low_items=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($item->id, $rows[0]['item']['id']);
        $this->assertSame('Mine', $rows[0]['location']['name']);
    }

    public function test_stock_list_does_not_leak_sibling_company_balances(): void
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

        [$ownItem, $ownLocation, $foreignItem, $foreignLocation] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $sibling) {
                $ownItem = InventoryItem::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own liner',
                ]);
                $ownLocation = InventoryLocation::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own shop',
                ]);
                $foreignItem = InventoryItem::factory()->create([
                    'company_id' => $sibling->id,
                    'name' => 'Beta liner',
                ]);
                $foreignLocation = InventoryLocation::factory()->create([
                    'company_id' => $sibling->id,
                    'name' => 'Beta warehouse',
                ]);

                return [$ownItem, $ownLocation, $foreignItem, $foreignLocation];
            },
        );

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $ownLocation->id,
                'item_id' => $ownItem->id,
                'counted_as' => 10,
                'reason' => 'own count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $foreignLocation->id,
                'item_id' => $foreignItem->id,
                'counted_as' => 7,
                'reason' => 'beta count',
            ])
            ->assertCreated();

        $adminList = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->json('data');

        $this->assertNotNull($this->stockRow($adminList, $ownItem->id, $ownLocation->id));
        $this->assertNotNull($this->stockRow($adminList, $foreignItem->id, $foreignLocation->id));

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view'],
            onlyAt: $tenant['franchise'],
        );

        $listed = $this->signedInAs($local)
            ->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->json('data');

        $this->assertNotNull($this->stockRow($listed, $ownItem->id, $ownLocation->id));
        $this->assertNull($this->stockRow($listed, $foreignItem->id, $foreignLocation->id));

        foreach ($listed as $row) {
            $locationId = $row['location']['id'] ?? $row['location_id'] ?? null;
            $itemId = $row['item']['id'] ?? $row['item_id'] ?? null;
            $this->assertNotSame($foreignLocation->id, $locationId);
            $this->assertNotSame($foreignItem->id, $itemId);
        }
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

        [$ownItem, $ownLocation, $foreignItem] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $sibling) {
                $ownItem = InventoryItem::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own liner',
                ]);
                $ownLocation = InventoryLocation::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => 'Own shop',
                ]);
                $foreignItem = InventoryItem::factory()->create([
                    'company_id' => $sibling->id,
                    'name' => 'Beta liner',
                ]);

                return [$ownItem, $ownLocation, $foreignItem];
            },
        );

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $ownLocation->id,
                'item_id' => $foreignItem->id,
                'counted_as' => 7,
                'reason' => 'sibling count',
            ])
            ->assertForbidden();

        $this->inTenant($tenant['org'], function () use ($foreignItem, $ownLocation) {
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($foreignItem->id, $ownLocation->id));
            $this->assertSame(0, InventoryAdjustment::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $ownLocation->id,
                'item_id' => $ownItem->id,
                'counted_as' => 10,
                'reason' => 'own count',
            ])
            ->assertCreated();

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $ownLocation->id,
                'item_id' => $ownItem->id,
                'counted_as' => 4,
                'reason' => 'recount',
            ])
            ->assertCreated();
    }

    public function test_stock_index_item_id_returns_a_row_that_is_not_on_page_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$first, $later, $location] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Main shop',
            ]);
            $first = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Aardvark liner',
            ]);
            $later = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Zebra liner',
            ]);

            return [$first, $later, $location];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $first->id,
                'counted_as' => 3,
                'reason' => 'count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $later->id,
                'counted_as' => 17,
                'reason' => 'count',
            ])
            ->assertCreated();

        $pageOne = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock?per_page=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $pageOne);
        $this->assertSame($first->id, $pageOne[0]['item']['id'] ?? null);
        $this->assertNull($this->stockRow($pageOne, $later->id, $location->id));

        $filtered = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/stock?per_page=1&item_id='.$later->id)
            ->assertOk()
            ->json('data');

        $row = $this->stockRow($filtered, $later->id, $location->id);
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row['qty_on_hand'], '17', 3));
    }

    /**
     * @param  array{org: \App\Models\Organization, company: Company, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: InventoryItem, 1: InventoryLocation}
     */
    private function place(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return [$item, $location];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function stockRow(array $rows, string $itemId, string $locationId): ?array
    {
        foreach ($rows as $row) {
            $rowItem = $row['item']['id'] ?? $row['item_id'] ?? null;
            $rowLocation = $row['location']['id'] ?? $row['location_id'] ?? null;

            if ($rowItem === $itemId && $rowLocation === $locationId) {
                return $row;
            }
        }

        return null;
    }
}
