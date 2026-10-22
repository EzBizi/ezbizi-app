<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Support\Inventory\InventoryItemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Sales orders: draft → open → fulfill, customer or counter note, prices gated.
 */
class InventorySaleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_stale_open_sale_cannot_fulfill_after_cancellation(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$customer, $item, $location] = $this->customerItemLocation($t);
        $this->stock($t, $item->id, $location->id, '10');
        $sale = $this->openSale($t['admin'], $customer, $item, $t);
        $this->inTenant($t['org'], function () use ($sale, $location, $t) {
            $stale = \App\Models\InventorySale::findOrFail($sale['id']);
            $line = $stale->lines()->firstOrFail();
            \App\Models\InventorySale::whereKey($stale->id)->update(['status' => 'cancelled']);
            $this->expectException(\Illuminate\Validation\ValidationException::class);
            app(\App\Support\Inventory\InventoryMover::class)->fulfillSale(
                $stale, $location, [['line' => $line, 'qty' => '1']], $t['admin'],
            );
        });
    }

    public function test_sell_ten_partial_fulfill_then_over_then_full(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$customer, $item, $location] = $this->customerItemLocation($tenant);
        $this->stock($tenant, $item->id, $location->id, '10.000');

        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'customer_id' => $customer->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 10, 'unit_price_minor' => 2500],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $draft['status']);
        $this->assertSame('unpaid', $draft['payment_status']);
        $this->assertStringStartsWith('SO-', $draft['number']);
        $lineId = $draft['lines'][0]['id'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$draft['id'].'/open')
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$draft['id'].'/fulfillments', [
                'location_id' => $location->id,
                'lines' => [
                    ['sale_line_id' => $lineId, 'qty' => 4],
                ],
            ])
            ->assertCreated();

        $this->assertOnHand($tenant, $item->id, $location->id, '6.000');
        $this->assertSaleStatus($tenant, $draft['id'], 'partial');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$draft['id'].'/fulfillments', [
                'location_id' => $location->id,
                'lines' => [
                    ['sale_line_id' => $lineId, 'qty' => 7],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This sale does not have that many left to fulfill.');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$draft['id'].'/fulfillments', [
                'location_id' => $location->id,
                'lines' => [
                    ['sale_line_id' => $lineId, 'qty' => 6],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sale.status', 'fulfilled');

        $this->assertOnHand($tenant, $item->id, $location->id, '0.000');
        $this->assertSaleStatus($tenant, $draft['id'], 'fulfilled');
    }

    public function test_counter_sale_needs_a_note_when_there_is_no_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [, $item] = $this->customerItemLocation($tenant);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'note' => 'Counter sale',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.note', 'Counter sale')
            ->assertJsonPath('data.customer', null);
    }

    public function test_company_view_cannot_file_a_sale_on_a_sibling_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [, $item] = $this->customerItemLocation($tenant);

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return \App\Models\Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        $this->inTenant($tenant['org'], function () use ($admin, $tenant) {
            $fresh = \App\Models\User::find($admin->id);
            $fresh->forceFill([
                'preferences' => array_merge($fresh->preferences ?? [], [
                    'navigation.scope' => 'company:'.$tenant['company']->id,
                ]),
            ])->save();
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'company_id' => $sibling->id,
                'note' => 'Counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_without_financials_drops_price_and_payment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$customer, $item] = $this->customerItemLocation($tenant);

        $sale = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'customer_id' => $customer->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1, 'unit_price_minor' => 1999],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1999, $sale['lines'][0]['unit_price_minor']);

        $viewer = $this->userWithRole(
            $tenant,
            'Stock clerk',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.create'],
        );

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/sales/'.$sale['id'])
            ->assertOk()
            ->assertJsonMissingPath('data.payment_status')
            ->assertJsonMissingPath('data.lines.0.unit_price_minor');
    }

    public function test_discontinued_item_cannot_be_sold(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $item = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'status' => InventoryItemStatus::Discontinued,
            ]);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'note' => 'Counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item is discontinued, so it cannot go out.');
    }

    public function test_pay_requires_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$customer, $item] = $this->customerItemLocation($tenant);

        $sale = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'customer_id' => $customer->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $clerk = $this->userWithRole(
            $tenant,
            'Stock clerk pay',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.edit'],
        );

        $this->signedInAs($clerk)
            ->postJson('/api/v1/inventory/sales/'.$sale['id'].'/pay', [
                'payment_status' => 'paid',
            ])
            ->assertForbidden();
    }

    public function test_cannot_cancel_after_fulfillment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$customer, $item, $location] = $this->customerItemLocation($tenant);
        $this->stock($tenant, $item->id, $location->id, '5.000');

        $sale = $this->openSale($admin, $customer, $item, $tenant);
        $lineId = $sale['lines'][0]['id'];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$sale['id'].'/fulfillments', [
                'location_id' => $location->id,
                'lines' => [
                    ['sale_line_id' => $lineId, 'qty' => 1],
                ],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$sale['id'].'/cancel')
            ->assertStatus(422);
    }

    /**
     * @param  array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: Customer, 1: InventoryItem, 2: InventoryLocation}
     */
    private function customerItemLocation(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::factory()->create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'name' => 'Pat Buyer',
            ]);
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => '6 mil liner',
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Shop',
            ]);

            return [$customer, $item, $location];
        });
    }

    /** @param  array{org: \App\Models\Organization}  $tenant */
    private function stock(array $tenant, string $itemId, string $locationId, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($itemId, $locationId, $qty) {
            InventoryBalance::create([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'qty_on_hand' => $qty,
            ]);
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise}  $tenant
     * @return array<string, mixed>
     */
    private function openSale($admin, Customer $customer, InventoryItem $item, array $tenant): array
    {
        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales', [
                'customer_id' => $customer->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 5],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/sales/'.$draft['id'].'/open')
            ->assertOk();

        $draft['status'] = 'open';

        return $draft;
    }

    /** @param  array{org: \App\Models\Organization}  $tenant */
    private function assertOnHand(array $tenant, string $itemId, string $locationId, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($itemId, $locationId, $qty) {
            $this->assertSame($qty, InventoryBalance::qtyOnHand($itemId, $locationId));
        });
    }

    /** @param  array{org: \App\Models\Organization}  $tenant */
    private function assertSaleStatus(array $tenant, string $id, string $status): void
    {
        $this->inTenant($tenant['org'], function () use ($id, $status) {
            $this->assertSame($status, InventorySale::query()->find($id)?->status->value);
        });
    }

    /** The search bar: `q` narrows by customer or number; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$customer, $item] = $this->customerItemLocation($t);
        $other = $this->inTenant($t['org'], fn () => Customer::factory()->create([
            'owning_franchise_id' => $t['franchise']->id,
            'name' => 'Quinn Hearth',
        ]));
        $first = $this->openSale($t['admin'], $customer, $item, $t);
        $this->openSale($t['admin'], $other, $item, $t);

        $url = '/api/v1/inventory/sales';
        $this->signedInAs($t['admin'])->getJson($url.'?q=quinn')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($t['admin'])->getJson($url.'?q='.urlencode($first['number']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first['id']);
        $this->signedInAs($t['admin'])->getJson($url.'?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($t['admin'])->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }
}
