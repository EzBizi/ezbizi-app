<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventorySummary;
use App\Support\Scoping\ViewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The one computation behind both the Dashboard box and the General tab.
 */
class InventorySummaryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_item_with_no_reorder_point_is_never_low(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // No reorder_at: one unit on hand must read as in stock, not low.
            $noPoint = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => null,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $noPoint->id,
                'location_id' => $location->id,
                'qty_on_hand' => '1.000',
            ]);
        });

        $counts = $this->countsFor($admin);

        $this->assertSame(1, $counts['in_stock']);
        $this->assertSame(0, $counts['low']);
        $this->assertSame(0, $counts['out']);
    }

    public function test_on_hand_at_the_reorder_point_is_low_not_in_stock(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // At the point, not below it: the boundary is inclusive.
            $atPoint = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => '5.000',
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $atPoint->id,
                'location_id' => $location->id,
                'qty_on_hand' => '5.000',
            ]);
        });

        $counts = $this->countsFor($admin);

        $this->assertSame(0, $counts['in_stock']);
        $this->assertSame(1, $counts['low']);
    }

    public function test_a_sibling_companys_items_are_absent_from_every_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return \App\Models\Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.financials'],
            onlyAt: $tenant['franchise'],
        );

        $this->inTenant($tenant['org'], function () use ($tenant, $sibling) {
            $theirs = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $sibling->id,
            ]);

            $theirItem = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $sibling->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => null,
                'last_cost_minor' => 1000,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $theirItem->id,
                'location_id' => $theirs->id,
                'qty_on_hand' => '99.000',
            ]);

            // Control. Without one item of our own on hand, every figure below
            // would read zero just as happily if this user could reach nothing
            // at all — and the test would be about a broken fixture rather
            // than about the sibling being excluded.
            $ours = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            $ourItem = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => null,
                'last_cost_minor' => 250,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $ourItem->id,
                'location_id' => $ours->id,
                'qty_on_hand' => '2.000',
            ]);
        });

        $counts = $this->countsFor($local);

        // Ours and only ours: one item, in stock, valued at 2 × 250. Their 99
        // at 1000 each would move every one of these numbers.
        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, $counts['in_stock']);
        $this->assertSame(500, $counts['stock_value_minor']);
    }

    /** @return array<string, int> */
    private function countsFor(User $user): array
    {
        return $this->inTenant(
            $user->organization,
            fn () => InventorySummary::counts($user, ViewScope::forUser($user)),
        );
    }
}
