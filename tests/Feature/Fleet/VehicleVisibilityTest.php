<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Who sees which vans (spec §4): franchise view includes that franchise’s
 * company pool, never a sibling company’s pool or another franchise’s tags.
 */
class VehicleVisibilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_franchise_user_sees_own_franchise_and_that_company_pool_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$franchiseA1, $vehicles] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $companyA = $tenant['company'];
            $franchiseA1 = $tenant['franchise'];

            $franchiseA2 = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $companyA->id,
                'name' => 'Alpha F2',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            $companyB = Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);

            $franchiseB1 = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $companyB->id,
                'name' => 'Beta F1',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            $vehicles = [
                'orgPool' => Vehicle::factory()->create([
                    'company_id' => null,
                    'franchise_id' => null,
                    'plate' => 'ORG-POOL',
                ]),
                'poolA' => Vehicle::factory()->create([
                    'company_id' => $companyA->id,
                    'franchise_id' => null,
                    'plate' => 'POOL-A',
                ]),
                'tagA1' => Vehicle::factory()->create([
                    'company_id' => $companyA->id,
                    'franchise_id' => $franchiseA1->id,
                    'plate' => 'TAG-A1',
                ]),
                'tagA2' => Vehicle::factory()->create([
                    'company_id' => $companyA->id,
                    'franchise_id' => $franchiseA2->id,
                    'plate' => 'TAG-A2',
                ]),
                'poolB' => Vehicle::factory()->create([
                    'company_id' => $companyB->id,
                    'franchise_id' => null,
                    'plate' => 'POOL-B',
                ]),
                'tagB1' => Vehicle::factory()->create([
                    'company_id' => $companyB->id,
                    'franchise_id' => $franchiseB1->id,
                    'plate' => 'TAG-B1',
                ]),
            ];

            return [$franchiseA1, $vehicles];
        });

        $viewer = $this->userWithRole(
            $tenant,
            'FleetViewer',
            ['fleet_management.view'],
            onlyAt: $franchiseA1,
        );

        $this->inTenant($tenant['org'], function () use ($viewer, $franchiseA1, $vehicles) {
            $viewer->forceFill([
                'preferences' => array_merge($viewer->preferences ?? [], [
                    'navigation.scope' => 'franchise:'.$franchiseA1->id,
                ]),
            ])->save();

            $fresh = User::find($viewer->id);
            $scope = ViewScope::forUser($fresh);

            $ids = InventoryVisibility::constrain(Vehicle::query(), $fresh, $scope)
                ->pluck('id')
                ->all();

            $this->assertContains($vehicles['orgPool']->id, $ids);
            $this->assertContains($vehicles['poolA']->id, $ids);
            $this->assertContains($vehicles['tagA1']->id, $ids);
            $this->assertNotContains($vehicles['tagA2']->id, $ids);
            $this->assertNotContains($vehicles['poolB']->id, $ids);
            $this->assertNotContains($vehicles['tagB1']->id, $ids);
        });
    }

    public function test_empty_reach_matches_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => null,
            ]);
        });

        $nowhere = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = \App\Models\Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Nowhere',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['fleet_management.view']);

            return User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => 'Nowhere',
                'email' => 'nowhere@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
        });

        $this->inTenant($tenant['org'], function () use ($nowhere) {
            $scope = ViewScope::forUser($nowhere->fresh());
            $count = InventoryVisibility::constrain(Vehicle::query(), $nowhere->fresh(), $scope)->count();
            $this->assertSame(0, $count);
        });
    }
}
