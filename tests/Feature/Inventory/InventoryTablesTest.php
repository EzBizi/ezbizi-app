<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventoryUnit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InventoryTablesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_inventory_tables_exist(): void
    {
        foreach ([
            'inventory_items',
            'inventory_locations',
            'inventory_balances',
            'inventory_vendors',
            'inventory_purchase_orders',
            'inventory_purchase_order_lines',
            'inventory_receives',
            'inventory_receive_lines',
            'inventory_transfers',
            'inventory_transfer_lines',
            'inventory_issues',
            'inventory_issue_lines',
            'inventory_adjustments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_backed_enums_expose_values_for_validation_rules(): void
    {
        $this->assertSame(['each', 'box', 'ft', 'in', 'gal', 'lb', 'kit', 'other'], InventoryUnit::values());
        $this->assertSame(['active', 'discontinued'], InventoryItemStatus::values());
        $this->assertSame(['warehouse', 'shop', 'cage', 'receiving', 'van', 'other'], InventoryLocationKind::values());
        $this->assertSame(['active', 'closed'], InventoryLocationStatus::values());
        $this->assertSame(['draft', 'open', 'received', 'cancelled'], InventoryPurchaseOrderStatus::values());
    }

    public function test_sku_is_unique_inside_a_place_not_the_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $coA = $alpha['company'];
        $coB = $this->inTenant($alpha['org'], fn () => Company::create([
            'organization_id' => $alpha['org']->id,
            'name' => 'Beta Co',
            'is_active' => true,
        ]));

        $this->insertItem($alpha, $coA, null, 'LINER-6');
        $this->insertItem($alpha, $coB, null, 'LINER-6'); // allowed

        $this->expectException(QueryException::class);
        $this->insertItem($alpha, $coA, null, 'LINER-6');
    }

    public function test_two_locations_cannot_share_a_vehicle(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        $this->insertLocation($tenant, name: 'Van A', vehicleId: $vehicleId);

        $this->expectException(QueryException::class);
        $this->insertLocation($tenant, name: 'Van B', vehicleId: $vehicleId);
    }

    public function test_qty_on_hand_may_be_negative_at_sql(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $itemId = $this->insertItem($tenant, $tenant['company'], null, 'LINER-6');
        $locationId = $this->insertLocation($tenant, name: 'Shop');

        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'qty_on_hand' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('inventory_balances')->where('item_id', $itemId)->count());
    }

    /**
     * @param  array{org: Organization, company: Company}  $tenant
     */
    private function insertItem(array $tenant, Company $company, mixed $franchise, ?string $sku): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('inventory_items')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $company->id,
            'franchise_id' => $franchise,
            'number' => 'I-'.substr($id, 0, 8),
            'sku' => $sku,
            'name' => '6 mil liner',
            'unit' => 'each',
            'status' => 'active',
            'reorder_at' => null,
            'last_cost_minor' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant
     */
    private function insertLocation(array $tenant, string $name, ?string $vehicleId = null): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('inventory_locations')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'name' => $name,
            'kind' => $vehicleId === null ? 'shop' : 'van',
            'status' => 'active',
            'vehicle_id' => $vehicleId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant
     */
    private function insertVehicle(array $tenant): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('vehicles')->insert([
            'id' => $id,
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'number' => 'V-00001',
            'label' => 'Unidentified vehicle',
            'year' => null,
            'make' => null,
            'model' => null,
            'plate' => null,
            'vin' => null,
            'colour' => null,
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'odometer_miles' => null,
            'next_service_on' => null,
            'next_service_miles' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
