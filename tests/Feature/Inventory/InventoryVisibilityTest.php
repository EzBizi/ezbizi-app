<?php

namespace Tests\Feature\Inventory;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Who sees which catalogue rows (spec §4): a franchise view includes that
 * franchise’s company General and the org register, never a sibling’s tags
 * or another company’s catalogue.
 */
class InventoryVisibilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_franchise_user_sees_own_general_and_org_not_sibling_or_other_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$franchiseA1, $rows] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $seed = $this->seedCatalogue($tenant);

            return [$tenant['franchise'], $seed['rows']];
        });

        $viewer = $this->userWithRole(
            $tenant,
            'InventoryViewer',
            [],
            onlyAt: $franchiseA1,
        );

        $this->inTenant($tenant['org'], function () use ($viewer, $franchiseA1, $rows) {
            $this->setView($viewer, 'franchise:'.$franchiseA1->id);

            $fresh = User::find($viewer->id);
            $scope = ViewScope::forUser($fresh);

            $ids = InventoryVisibility::constrain(
                (new InventoryVisibilityRow)->newQuery(),
                $fresh,
                $scope,
            )->pluck('id')->all();

            $this->assertContains($rows['org']->id, $ids);
            $this->assertContains($rows['generalA']->id, $ids);
            $this->assertContains($rows['tagA1']->id, $ids);
            $this->assertNotContains($rows['tagA2']->id, $ids);
            $this->assertNotContains($rows['generalB']->id, $ids);
            $this->assertNotContains($rows['tagB1']->id, $ids);
        });
    }

    public function test_organization_view_sees_every_companys_items(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $rows = $this->inTenant($tenant['org'], fn () => $this->seedCatalogue($tenant)['rows']);

        $viewer = $this->userWithRole($tenant, 'OrgViewer', []);

        $this->inTenant($tenant['org'], function () use ($viewer, $rows) {
            $fresh = User::find($viewer->id);
            $scope = ViewScope::forUser($fresh);

            $this->assertTrue($scope->isOrganization());

            $ids = InventoryVisibility::constrain(
                (new InventoryVisibilityRow)->newQuery(),
                $fresh,
                $scope,
            )->pluck('id')->all();

            $this->assertContains($rows['org']->id, $ids);
            $this->assertContains($rows['generalA']->id, $ids);
            $this->assertContains($rows['tagA1']->id, $ids);
            $this->assertContains($rows['tagA2']->id, $ids);
            $this->assertContains($rows['generalB']->id, $ids);
            $this->assertContains($rows['tagB1']->id, $ids);
        });
    }

    public function test_company_b_user_does_not_see_company_a_catalogue_items(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $seed = $this->inTenant($tenant['org'], fn () => $this->seedCatalogue($tenant));

        $viewer = $this->userAtCompany($tenant, $seed['companyB'], 'CompanyBViewer');

        $this->inTenant($tenant['org'], function () use ($viewer, $seed) {
            $this->setView($viewer, 'company:'.$seed['companyB']->id);

            $fresh = User::find($viewer->id);
            $scope = ViewScope::forUser($fresh);

            $ids = InventoryVisibility::constrain(
                (new InventoryVisibilityRow)->newQuery(),
                $fresh,
                $scope,
            )->pluck('id')->all();

            $this->assertContains($seed['rows']['org']->id, $ids);
            $this->assertContains($seed['rows']['generalB']->id, $ids);
            $this->assertContains($seed['rows']['tagB1']->id, $ids);
            $this->assertNotContains($seed['rows']['generalA']->id, $ids);
            $this->assertNotContains($seed['rows']['tagA1']->id, $ids);
            $this->assertNotContains($seed['rows']['tagA2']->id, $ids);
        });
    }

    public function test_empty_reach_matches_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->insertItem($tenant, $tenant['company'], null, 'POOL-A');
        });

        $nowhere = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Nowhere',
                'is_system' => false,
            ]);

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
            $count = InventoryVisibility::constrain(
                (new InventoryVisibilityRow)->newQuery(),
                $nowhere->fresh(),
                $scope,
            )->count();
            $this->assertSame(0, $count);
        });
    }

    /**
     * Two companies, two franchises under A, one franchise under B.
     * Org item, company-A General, franchise A, sibling franchise, company B,
     * franchise B.
     *
     * @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant
     * @return array{companyB: Company, rows: array<string, object{id: string}>}
     */
    private function seedCatalogue(array $tenant): array
    {
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

        $rows = [
            'org' => (object) ['id' => $this->insertItem($tenant, null, null, 'ORG')],
            'generalA' => (object) ['id' => $this->insertItem($tenant, $companyA, null, 'POOL-A')],
            'tagA1' => (object) ['id' => $this->insertItem($tenant, $companyA, $franchiseA1, 'TAG-A1')],
            'tagA2' => (object) ['id' => $this->insertItem($tenant, $companyA, $franchiseA2, 'TAG-A2')],
            'generalB' => (object) ['id' => $this->insertItem($tenant, $companyB, null, 'POOL-B')],
            'tagB1' => (object) ['id' => $this->insertItem($tenant, $companyB, $franchiseB1, 'TAG-B1')],
        ];

        return ['companyB' => $companyB, 'rows' => $rows];
    }

    /**
     * @param  array{org: Organization}  $tenant
     */
    private function userAtCompany(array $tenant, Company $company, string $roleName): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $company, $roleName) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => $roleName,
                'is_system' => false,
            ]);

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

    private function setView(User $user, string $scope): void
    {
        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], [
                'navigation.scope' => $scope,
            ]),
        ])->save();
    }

    /**
     * @param  array{org: Organization}  $tenant
     */
    private function insertItem(array $tenant, ?Company $company, ?Franchise $franchise, string $sku): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('inventory_items')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $company?->id,
            'franchise_id' => $franchise?->id,
            'number' => 'I-'.substr($id, 0, 8),
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'each',
            'status' => 'active',
            'reorder_at' => null,
            'last_cost_minor' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}

/**
 * Eloquent stand-in for inventory_items until Task 3 ships InventoryItem.
 * Same place columns the helper predicates on.
 */
class InventoryVisibilityRow extends Model
{
    protected $table = 'inventory_items';

    public $incrementing = false;

    protected $keyType = 'string';
}
