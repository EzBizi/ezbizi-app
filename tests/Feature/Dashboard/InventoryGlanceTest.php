<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\InventoryGlance;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory dashboard glance (stock health pie + reach sentences).
 *
 * Snapshot of active-item In/Low/Out under InventoryVisibility. Open POs and
 * stock_value (when permitted) ride as sentences / data keys — not slices.
 */
class InventoryGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /** A second franchise with its own user, reaching only that franchise. */
    private function secondBranch(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    private function taggedItem(Franchise $franchise, array $attributes = []): InventoryItem
    {
        return $this->inTenant(fn () => InventoryItem::factory()->create($attributes + [
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $franchise->id,
        ]));
    }

    private function taggedLocation(Franchise $franchise): InventoryLocation
    {
        return $this->inTenant(fn () => InventoryLocation::factory()->create([
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $franchise->id,
        ]));
    }

    private function setOnHand(InventoryItem $item, InventoryLocation $location, int|string $qty): void
    {
        $this->inTenant(function () use ($item, $location, $qty) {
            InventoryBalance::create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => is_int($qty) ? sprintf('%d.000', $qty) : $qty,
            ]);
        });
    }

    /**
     * Somebody holding exactly these permissions, assigned to F1.
     *
     * @param  string[]  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys, string $email = 'limited@alpha.test'): User
    {
        return $this->inTenant(function () use ($permissionKeys, $email) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Limited '.bin2hex(random_bytes(2)), 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', $permissionKeys)->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => $email,
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }

    public function test_a_branch_user_sees_only_items_in_their_reach(): void
    {
        [$second, $branch] = $this->secondBranch();
        $this->taggedItem($this->alpha['franchise']);
        $this->taggedItem($this->alpha['franchise']);
        $this->taggedItem($second);

        $payload = app(InventoryGlance::class)->resolve($branch, DashboardPeriod::for($branch), [])->toArray();
        $this->assertSame(1, array_sum(array_column($payload['data']['slices'], 'total')));
    }

    public function test_zero_items_and_locations_is_a_sentence(): void
    {
        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No items to show.', $payload['data']['sentence']);
    }

    public function test_qty_at_or_below_reorder_is_low_and_zero_is_out(): void
    {
        $location = $this->taggedLocation($this->alpha['franchise']);

        $in = $this->taggedItem($this->alpha['franchise'], [
            'name' => 'In stock liner',
            'reorder_at' => '4.000',
        ]);
        $low = $this->taggedItem($this->alpha['franchise'], [
            'name' => 'Low liner',
            'reorder_at' => '4.000',
        ]);
        $this->taggedItem($this->alpha['franchise'], [
            'name' => 'Out liner',
            'reorder_at' => '4.000',
        ]);
        $this->taggedItem($this->alpha['franchise'], [
            'name' => 'Discontinued empty',
            'status' => InventoryItemStatus::Discontinued,
            'reorder_at' => '4.000',
        ]);

        $this->setOnHand($in, $location, 10);
        $this->setOnHand($low, $location, 3);

        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertFalse($payload['data']['empty']);
        $this->assertSame(
            [
                ['label' => 'In stock', 'total' => 1],
                ['label' => 'Low', 'total' => 1],
                ['label' => 'Out of stock', 'total' => 1],
            ],
            $payload['data']['slices'],
        );
        $this->assertContains('4 items listed.', $payload['data']['sentences']);
        $this->assertContains('1 location listed.', $payload['data']['sentences']);
        $this->assertContains('0 open purchase orders.', $payload['data']['sentences']);
    }

    public function test_open_purchase_orders_are_draft_or_open(): void
    {
        $this->taggedItem($this->alpha['franchise']);

        $this->inTenant(function () {
            $vendor = InventoryVendor::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
            ]);

            foreach ([
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
                InventoryPurchaseOrderStatus::Received,
                InventoryPurchaseOrderStatus::Cancelled,
            ] as $status) {
                InventoryPurchaseOrder::create([
                    'company_id' => $this->alpha['company']->id,
                    'franchise_id' => $this->alpha['franchise']->id,
                    'vendor_id' => $vendor->id,
                    'number' => 'PO-'.bin2hex(random_bytes(3)),
                    'status' => $status,
                ]);
            }
        });

        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertContains('2 open purchase orders.', $payload['data']['sentences']);
    }

    /**
     * No stock value, even with inventory.financials (Jose, 2026-09-29: no
     * financial information on the dashboard). The figure is still computed
     * for the Inventory tab — InventorySummaryTest pins it there.
     */
    public function test_no_stock_value_even_with_financials(): void
    {
        $location = $this->taggedLocation($this->alpha['franchise']);
        $item = $this->taggedItem($this->alpha['franchise'], [
            'last_cost_minor' => 250,
        ]);
        $this->setOnHand($item, $location, 2);

        $money = $this->userWithPermissions(
            ['inventory.view', 'inventory.financials'],
            'money@alpha.test',
        );

        $payload = app(InventoryGlance::class)->resolve(
            $money, DashboardPeriod::for($money), []
        )->toArray();

        $this->assertSame(1, $payload['data']['slices'][0]['total']);
        $json = json_encode($payload);
        $this->assertStringNotContainsString('amount_minor', $json);
        $this->assertStringNotContainsString('500', $json);
        $this->assertStringNotContainsString('USD', $json);
    }

    public function test_the_stock_slices_ignore_the_dashboard_period(): void
    {
        $this->taggedItem($this->alpha['franchise']);

        $week = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $year = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }
}
