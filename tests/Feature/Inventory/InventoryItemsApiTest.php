<?php

namespace Tests\Feature\Inventory;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Role;
use App\Models\User;
use App\Support\Inventory\InventoryFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Catalogue CRUD: place write, per-place SKU, costs stripped without financials.
 */
class InventoryItemsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_franchise_user_cannot_file_an_item_on_a_sibling_company(): void
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
            ['inventory.view', 'inventory.create'],
            onlyAt: $tenant['franchise'],
        );

        $message = $this->signedInAs($local)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $sibling->id,
                'sku' => 'LINER-6',
                'name' => '6 mil liner',
                'unit' => 'each',
            ])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('assigned', $message);

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'sku' => 'OWN-1',
                'name' => 'Own liner',
                'unit' => 'each',
            ])
            ->assertCreated();
    }

    public function test_company_view_cannot_file_an_item_on_a_sibling_company(): void
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

        $this->inTenant($tenant['org'], function () use ($admin, $tenant) {
            $fresh = User::find($admin->id);
            $fresh->forceFill([
                'preferences' => array_merge($fresh->preferences ?? [], [
                    'navigation.scope' => 'company:'.$tenant['company']->id,
                ]),
            ])->save();
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $sibling->id,
                'sku' => 'BETA-1',
                'name' => 'Beta liner',
                'unit' => 'each',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id'])
            ->assertJsonFragment([
                'company_id' => ['That company is not in this view.'],
            ]);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $tenant['company']->id,
                'sku' => 'OWN-1',
                'name' => 'Own liner',
                'unit' => 'each',
            ])
            ->assertCreated();
    }

    public function test_without_costs_drops_last_cost_and_line_unit_costs(): void
    {
        $row = InventoryFinancials::withoutCosts([
            'name' => 'kept',
            'last_cost_minor' => 1_250,
            'unit_cost_minor' => 400,
            'lines' => [
                ['qty' => '1.000', 'unit_cost_minor' => 400],
            ],
        ]);

        $this->assertSame('kept', $row['name']);
        $this->assertArrayNotHasKey('last_cost_minor', $row);
        $this->assertArrayNotHasKey('unit_cost_minor', $row);
        $this->assertSame('1.000', $row['lines'][0]['qty']);
        $this->assertArrayNotHasKey('unit_cost_minor', $row['lines'][0]);
    }

    public function test_duplicate_sku_in_the_same_company_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $payload = [
            'company_id' => $tenant['company']->id,
            'sku' => 'LINER-6',
            'name' => '6 mil liner',
            'unit' => 'each',
        ];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', $payload)
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sku'])
            ->assertJsonFragment([
                'sku' => ['This place already has an item with that SKU.'],
            ]);
    }

    public function test_two_companies_may_share_a_sku(): void
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

        $payload = [
            'sku' => 'LINER-6',
            'name' => '6 mil liner',
            'unit' => 'each',
        ];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', $payload + ['company_id' => $tenant['company']->id])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', $payload + ['company_id' => $sibling->id])
            ->assertCreated();
    }

    public function test_admin_creates_item_with_number_and_strips_cost_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'sku' => 'LINER-6',
                'name' => '6 mil liner',
                'unit' => 'each',
                'reorder_at' => '4.5',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['id']);
        $this->assertStringStartsWith('I-', $created['number']);
        $this->assertSame('LINER-6', $created['sku']);
        $this->assertSame('6 mil liner', $created['name']);
        $this->assertSame('each', $created['unit']);
        $this->assertSame('active', $created['status']);
        $this->assertSame($tenant['company']->id, $created['company']['id']);
        $this->assertSame($tenant['franchise']->id, $created['franchise']['id']);

        $this->inTenant($tenant['org'], function () use ($created) {
            InventoryItem::query()->whereKey($created['id'])->update([
                'last_cost_minor' => 1_250,
            ]);
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['inventory.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'inventory.view',
            'inventory.financials',
        ]);

        $stripped = $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/items/'.$created['id'])
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('last_cost_minor', $stripped);

        $kept = $this->signedInAs($money)
            ->getJson('/api/v1/inventory/items/'.$created['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1_250, $kept['last_cost_minor']);

        $listed = $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/items')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($listed);
        $this->assertArrayNotHasKey('last_cost_minor', $listed[0]);
    }

    public function test_patch_discontinues_and_delete_refuses_history(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $empty = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'sku' => 'EMPTY-1',
            ]);
        });

        $discontinued = $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$empty->id, [
                'status' => 'discontinued',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('discontinued', $discontinued['status']);

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/inventory/items/'.$empty->id)
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () use ($empty) {
            $this->assertFalse(InventoryItem::query()->whereKey($empty->id)->exists());
        });

        $withHistory = $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            InventoryAdjustment::create([
                'location_id' => $location->id,
                'item_id' => $item->id,
                'qty_before' => '0.000',
                'qty_after' => '1.000',
                'reason' => 'counted',
                'adjusted_on' => '2026-08-24',
            ]);

            return $item;
        });

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/inventory/items/'.$withHistory->id)
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This item has history, so it cannot be removed. Mark it discontinued instead.',
            ]);

        $this->inTenant($tenant['org'], function () use ($withHistory) {
            $this->assertTrue(InventoryItem::query()->whereKey($withHistory->id)->exists());
        });
    }

    public function test_patch_company_refuses_stock_and_rechecks_create_reach(): void
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

        [$item, $location, $empty] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Stocked liner',
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Main shop',
            ]);
            $empty = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Empty liner',
            ]);

            return [$item, $location, $empty];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 10,
                'reason' => 'opening count',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$item->id, [
                'company_id' => $sibling->id,
                'franchise_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Move the stock out first.',
            ]);

        $this->inTenant($tenant['org'], function () use ($item, $location) {
            $row = InventoryItem::query()->find($item->id);
            $this->assertSame($item->company_id, $row?->company_id);
            $this->assertSame($item->franchise_id, $row?->franchise_id);
            $this->assertSame('10.000', InventoryBalance::qtyOnHand($item->id, $location->id));
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$empty->id, [
                'company_id' => $sibling->id,
                'franchise_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.company.id', $sibling->id);

        $local = $this->userAtCompany(
            $tenant,
            $tenant['company'],
            'CompanyClerk',
            ['inventory.view', 'inventory.edit', 'inventory.create'],
        );

        $ownEmpty = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'name' => 'Clerk liner',
            ]);
        });

        $this->signedInAs($local)
            ->patchJson('/api/v1/inventory/items/'.$ownEmpty->id, [
                'company_id' => null,
            ])
            ->assertForbidden();

        $this->signedInAs($local)
            ->patchJson('/api/v1/inventory/items/'.$ownEmpty->id, [
                'company_id' => $sibling->id,
                'franchise_id' => null,
            ])
            ->assertForbidden();

        $this->inTenant($tenant['org'], function () use ($ownEmpty, $tenant) {
            $row = InventoryItem::query()->find($ownEmpty->id);
            $this->assertSame($tenant['company']->id, $row?->company_id);
        });
    }

    public function test_admin_creates_an_item_on_the_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'sku' => 'ORG-LINER',
                'name' => 'Org liner',
                'unit' => 'each',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNull($created['company']);
        $this->assertNull($created['franchise']);
        $this->assertStringStartsWith('I-', $created['number']);

        $this->inTenant($tenant['org'], function () use ($created) {
            $row = InventoryItem::query()->find($created['id']);
            $this->assertNull($row?->company_id);
            $this->assertNull($row?->franchise_id);
        });
    }

    public function test_organization_item_cannot_carry_a_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/items', [
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Orphan tag',
                'unit' => 'each',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['franchise_id']);
    }

    public function test_company_user_cannot_file_an_organization_item(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $local = $this->userAtCompany(
            $tenant,
            $tenant['company'],
            'CompanyClerk',
            ['inventory.view', 'inventory.create'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/items', [
                'name' => 'Org liner',
                'unit' => 'each',
            ])
            ->assertForbidden();
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  string[]  $permissionKeys
     */
    private function userAtCompany(
        array $tenant,
        Company $company,
        string $roleName,
        array $permissionKeys,
    ): User {
        return $this->inTenant($tenant['org'], function () use ($tenant, $company, $roleName, $permissionKeys) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => $roleName,
                'is_system' => false,
            ]);
            $role->syncPermissionKeys($permissionKeys);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $roleName,
                'email' => strtolower($roleName).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $company->id,
            ]);

            return $user;
        });
    }
}
