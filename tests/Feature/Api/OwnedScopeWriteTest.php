<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventorySale;
use App\Models\InventoryVendor;
use App\Models\Lead;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\ServiceLabel;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Models\Vehicle;
use App\Support\Scoping\OwnedScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OwnedScopeWriteTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant();
    }

    public function test_franchise_reader_cannot_modify_inherited_company_general_rows(): void
    {
        $actor = $this->userWithRole($this->tenant, 'Manager', [
            'inventory.view', 'inventory.edit', 'inventory.delete', 'services.view', 'services.edit', 'services.delete',
            'training.manage', 'fleet_management.view', 'fleet_management.edit', 'fleet_management.delete',
            'leads.view', 'leads.edit', 'leads.delete',
        ], onlyAt: $this->tenant['franchise']);
        app(TenantContext::class)->run($this->tenant['org'], function () use ($actor) {
            foreach ($this->models() as $model) {
                $row = new $model;
                $row->forceFill(['organization_id' => $this->tenant['org']->id, 'company_id' => $this->tenant['company']->id, 'franchise_id' => null]);
                $this->assertTrue(Gate::forUser($actor)->allows('view', $row), $model.' inherited read');
                $this->assertFalse(Gate::forUser($actor)->allows('update', $row), $model.' must require hard company authority');
            }
        });
    }

    public function test_narrowed_organization_admin_cannot_patch_or_delete_org_catalogue_without_scope_fields(): void
    {
        [$item, $service] = app(TenantContext::class)->run($this->tenant['org'], fn () => [
            InventoryItem::factory()->create(['company_id' => null, 'franchise_id' => null]),
            Service::factory()->create(['company_id' => null, 'franchise_id' => null]),
        ]);
        $this->signedInAs($this->tenant['admin'])->withHeader('X-EzBizi-View', 'company:'.$this->tenant['company']->id);
        foreach (['inventory/items/'.$item->id, 'services/'.$service->id] as $path) {
            $this->patchJson('/api/v1/'.$path, ['name' => 'Changed'])->assertForbidden();
            $this->deleteJson('/api/v1/'.$path)->assertForbidden();
        }
    }

    public function test_org_admin_view_must_be_at_or_above_the_row_for_every_catalogue_policy(): void
    {
        app(TenantContext::class)->run($this->tenant['org'], function () {
            $sibling = Company::create(['name' => 'Sibling', 'is_active' => true]);
            $admin = $this->tenant['admin'];
            $admin->preferences = ['navigation.scope' => 'company:'.$this->tenant['company']->id];
            foreach ($this->models() as $model) {
                foreach ([null, $sibling->id] as $companyId) {
                    $row = new $model;
                    $row->forceFill(['organization_id' => $this->tenant['org']->id, 'company_id' => $companyId, 'franchise_id' => null]);
                    $this->assertFalse(Gate::forUser($admin)->allows('update', $row), $model.' cannot write above/outside current view');
                }
            }
        });
    }

    public function test_write_scope_intersects_assignment_hierarchy_view_tenant_and_active_identity(): void
    {
        $other = $this->provisionTenant('Other');
        app(TenantContext::class)->run($this->tenant['org'], function () use ($other) {
            $org = $this->tenant['org']->id;
            $parent = $this->tenant['company'];
            $child = Company::create(['name' => 'Child', 'parent_company_id' => $parent->id]);
            $sibling = Company::create(['name' => 'Sibling']);
            $branch = Franchise::create(['name' => 'Child branch', 'company_id' => $child->id]);
            $admin = $this->tenant['admin'];
            $write = fn ($actor, $type, $id) => OwnedScope::mayWrite($actor, $org, $type, $id);
            $admin->preferences = ['navigation.scope' => 'company:'.$parent->id];
            $this->assertTrue($write($admin, 'company', $parent->id));
            $this->assertTrue($write($admin, 'company', $child->id));
            $this->assertTrue($write($admin, 'franchise', $branch->id));
            $this->assertFalse($write($admin, 'company', $sibling->id));
            $this->assertFalse($write($admin, 'organization', $org));
            $admin->preferences = ['navigation.scope' => 'company:'.$child->id];
            $this->assertFalse($write($admin, 'company', $parent->id));
            $this->assertTrue($write($admin, 'company', $child->id));
            $admin->preferences = ['navigation.scope' => 'franchise:'.$branch->id];
            $this->assertFalse($write($admin, 'company', $child->id));
            $this->assertFalse($write($admin, 'franchise', $this->tenant['franchise']->id));
            $this->assertTrue($write($admin, 'franchise', $branch->id));
            $admin->preferences = [];
            $this->assertTrue($write($admin, 'organization', $org));
            $this->assertFalse($write($admin, 'company', $other['company']->id));
            $this->assertFalse(OwnedScope::mayWrite($admin, $other['org']->id, 'organization', null));
            $this->assertFalse($write($admin, 'unknown', $org));
            $admin->is_active = false;
            $this->assertFalse($write($admin, 'organization', $org));
            $this->assertFalse($write($admin, 'company', $parent->id));

            $manager = $this->userWithRole($this->tenant, 'Company manager', ['inventory.edit']);
            $manager->assignments()->delete();
            Assignment::create(['organization_id' => $org, 'user_id' => $manager->id, 'assigned_company_id' => $parent->id]);
            $manager->unsetRelation('assignments');
            $this->assertTrue($write($manager, 'company', $child->id));
            $this->assertTrue($write($manager, 'franchise', $branch->id));
            $this->assertFalse($write($manager, 'organization', null));
            $this->assertFalse($write($manager, 'company', $sibling->id));
        });
    }

    private function models(): array
    {
        return [InventoryItem::class, InventoryLocation::class,
            InventoryVendor::class, InventoryPurchaseOrder::class,
            InventorySale::class, Service::class, ServiceGroup::class,
            ServiceLabel::class, TrainingGuide::class,
            TrainingTest::class, Vehicle::class, Lead::class];
    }
}
