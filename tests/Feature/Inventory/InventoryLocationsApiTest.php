<?php

namespace Tests\Feature\Inventory;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Locations CRUD: place write, van uniqueness, close/move while stock remains.
 */
class InventoryLocationsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_two_van_locations_on_one_vehicle_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $payload = [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Van 12',
            'kind' => 'van',
            'vehicle_id' => $vehicle->id,
        ];

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', $payload)
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                ...$payload,
                'name' => 'Van 12 again',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id'])
            ->assertJsonFragment([
                'vehicle_id' => ['That vehicle already is a location.'],
            ]);

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $this->assertSame(1, InventoryLocation::query()->where('vehicle_id', $vehicle->id)->count());
        });
    }

    public function test_vehicle_from_another_company_is_refused(): void
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

        $foreign = $this->inTenant($tenant['org'], function () use ($sibling) {
            return Vehicle::factory()->create([
                'company_id' => $sibling->id,
            ]);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'name' => 'Borrowed van',
                'kind' => 'van',
                'vehicle_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryLocation::query()->count());
        });
    }

    public function test_franchise_user_cannot_file_a_location_on_a_sibling_company(): void
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
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $sibling->id,
                'name' => 'Beta cage',
                'kind' => 'cage',
            ])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('assigned', $message);

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Own cage',
                'kind' => 'cage',
            ])
            ->assertCreated();
    }

    public function test_company_view_cannot_file_a_location_on_a_sibling_company(): void
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

        $this->lookingAt($admin, $tenant['org'], 'company:'.$tenant['company']->id);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $sibling->id,
                'name' => 'Beta cage',
                'kind' => 'cage',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id'])
            ->assertJsonFragment([
                'company_id' => ['That company is not in this view.'],
            ]);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'name' => 'Own cage',
                'kind' => 'cage',
            ])
            ->assertCreated();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, InventoryLocation::query()->count());
        });
    }

    public function test_company_view_cannot_file_a_location_on_the_group(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $this->lookingAt($admin, $tenant['org'], 'company:'.$tenant['company']->id);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'name' => 'Group cage',
                'kind' => 'cage',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InventoryLocation::query()->count());
        });
    }

    public function test_parent_company_view_can_file_on_a_sub_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $child = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Company::create([
                'organization_id' => $tenant['org']->id,
                'parent_company_id' => $tenant['company']->id,
                'name' => 'Sub Co',
                'is_active' => true,
            ]);
        });

        $this->lookingAt($admin, $tenant['org'], 'company:'.$tenant['company']->id);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $child->id,
                'name' => 'Sub cage',
                'kind' => 'cage',
            ])
            ->assertCreated();
    }

    public function test_admin_creates_shop_and_van_and_lists_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $shop = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Main shop',
                'kind' => 'shop',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($shop['id']);
        $this->assertSame('Main shop', $shop['name']);
        $this->assertSame('shop', $shop['kind']);
        $this->assertSame('active', $shop['status']);
        $this->assertNull($shop['vehicle']);
        $this->assertSame($tenant['company']->id, $shop['company']['id']);
        $this->assertSame($tenant['franchise']->id, $shop['franchise']['id']);

        $van = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Van 12',
                'kind' => 'van',
                'vehicle_id' => $vehicle->id,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('van', $van['kind']);
        $this->assertSame($vehicle->id, $van['vehicle']['id']);

        $listed = $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/locations')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $listed);

        $this->signedInAs($admin)
            ->getJson('/api/v1/inventory/locations/'.$shop['id'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Main shop');
    }

    public function test_close_and_company_change_refused_while_stock_remains(): void
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

        $empty = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Empty shop',
            ]);
        });

        $closed = $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/locations/'.$empty->id, [
                'status' => 'closed',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('closed', $closed['status']);

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/inventory/locations/'.$empty->id)
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () use ($empty) {
            $this->assertFalse(InventoryLocation::query()->whereKey($empty->id)->exists());
        });

        $withStock = $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Stocked shop',
            ]);
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            InventoryBalance::create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => '4.000',
            ]);

            return $location;
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/locations/'.$withStock->id, [
                'status' => 'closed',
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This location still has stock. Move or adjust it to zero first.',
            ]);

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/locations/'.$withStock->id, [
                'company_id' => $sibling->id,
                'franchise_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Move the stock out first.',
            ]);

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/inventory/locations/'.$withStock->id)
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This location still has stock. Move or adjust it to zero first.',
            ]);

        $this->inTenant($tenant['org'], function () use ($withStock) {
            $this->assertTrue(InventoryLocation::query()->whereKey($withStock->id)->exists());
            $row = InventoryLocation::query()->find($withStock->id);
            $this->assertSame($withStock->company_id, $row?->company_id);
            $this->assertSame('active', $row?->status->value);
        });
    }

    public function test_delete_refuses_zero_qty_location_that_has_history(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$item, $location] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $item = InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Counted shop',
            ]);

            return [$item, $location];
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
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $location->id,
                'item_id' => $item->id,
                'counted_as' => 0,
                'reason' => 'cleared',
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/inventory/locations/'.$location->id)
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This location has history, so it cannot be removed.',
            ]);

        $this->inTenant($tenant['org'], function () use ($item, $location) {
            $this->assertTrue(InventoryLocation::query()->whereKey($location->id)->exists());
            $this->assertTrue($location->fresh()->isEmpty());
            $this->assertSame('0.000', InventoryBalance::qtyOnHand($item->id, $location->id));
        });
    }

    public function test_admin_creates_a_location_on_the_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/locations', [
                'name' => 'Org warehouse',
                'kind' => 'warehouse',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNull($created['company']);
        $this->assertNull($created['franchise']);

        $this->inTenant($tenant['org'], function () use ($created) {
            $row = InventoryLocation::query()->find($created['id']);
            $this->assertNull($row?->company_id);
            $this->assertNull($row?->franchise_id);
        });
    }

    public function test_organization_location_cannot_carry_a_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/locations', [
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Orphan cage',
                'kind' => 'cage',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['franchise_id']);
    }

    public function test_company_user_cannot_file_an_organization_location(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $local = $this->userAtCompany(
            $tenant,
            $tenant['company'],
            'CompanyClerk',
            ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view', 'inventory.create'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/inventory/locations', [
                'name' => 'Org warehouse',
                'kind' => 'warehouse',
            ])
            ->assertForbidden();
    }

    public function test_kind_van_requires_a_vehicle(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inventory/locations', [
                'company_id' => $tenant['company']->id,
                'name' => 'Nameless van',
                'kind' => 'van',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    /**
     * @param  array{org: Organization}  $tenant
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

    private function lookingAt(User $user, Organization $organization, string $scope): void
    {
        $this->inTenant($organization, function () use ($user, $scope) {
            $fresh = User::find($user->id);
            $fresh->forceFill([
                'preferences' => array_merge($fresh->preferences ?? [], [
                    'navigation.scope' => $scope,
                ]),
            ])->save();
        });
    }
}
