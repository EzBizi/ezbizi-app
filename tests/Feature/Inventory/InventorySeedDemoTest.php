<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Demo catalogue plants on the named company, and is idempotent. */
class InventorySeedDemoTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_seed_demo_plants_items_on_the_named_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $tenant['company']->forceFill(['name' => 'EzBizi'])->save();
        });

        $this->artisan('inventory:seed-demo', ['company' => 'EzBizi'])
            ->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->assertTrue(
                InventoryItem::query()->where('sku', 'DEMO-LINER')->exists(),
            );
            $this->assertTrue(
                InventoryLocation::query()->where('name', 'Demo warehouse')->exists(),
            );
            $this->assertTrue(
                InventoryVendor::query()->where('name', 'Demo Supply Co')->exists(),
            );
            $this->assertTrue(
                Customer::query()->where('name', 'Demo Counter')->exists(),
            );
            $this->assertSame(
                $tenant['company']->id,
                InventoryItem::query()->where('sku', 'DEMO-LINER')->value('company_id'),
            );
        });
    }

    public function test_seed_demo_is_idempotent_on_sku(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $tenant['company']->forceFill(['name' => 'EzBizi'])->save();
        });

        $this->artisan('inventory:seed-demo', ['company' => 'EzBizi'])
            ->assertSuccessful();
        $this->artisan('inventory:seed-demo', ['company' => 'EzBizi'])
            ->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(
                1,
                InventoryItem::query()->where('sku', 'DEMO-LINER')->count(),
            );
            $this->assertSame(
                1,
                Customer::query()->where('name', 'Demo Counter')->count(),
            );
        });
    }

    public function test_seed_demo_refuses_a_name_that_is_not_a_company(): void
    {
        $this->provisionTenant('Alpha');

        $this->artisan('inventory:seed-demo', ['company' => 'No Such Co'])
            ->assertFailed();
    }
}
