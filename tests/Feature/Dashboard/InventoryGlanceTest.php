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
                'company_id' => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
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

    /**
     * Items listed leads; low and out follow. Counts only — never stock value —
     * and a snapshot, so every previous is null. Distinct values (4 / 1 / 2) so
     * a figure reading the wrong count cannot pass.
     */
    public function test_figures_are_items_listed_then_low_then_out(): void
    {
        $location = $this->taggedLocation($this->alpha['franchise']);

        $in = $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000', 'last_cost_minor' => 250]);
        $low = $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);

        $this->setOnHand($in, $location, 10);
        $this->setOnHand($low, $location, 3);

        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertSame([
            ['key' => 'items', 'label' => 'items listed', 'value' => 4, 'previous' => null],
            ['key' => 'low', 'label' => 'low', 'value' => 1, 'previous' => null],
            ['key' => 'out', 'label' => 'out of stock', 'value' => 2, 'previous' => null],
            // The administrator holds inventory.financials, so the money
            // figure follows the counts (test_stock_value_is_a_figure_only_with_financials).
            ['key' => 'stock_value', 'label' => 'stock value', 'value' => 2500, 'previous' => null, 'currency' => 'USD'],
        ], $payload['data']['figures']);
    }

    /**
     * Out of stock is broken (bad), low is about to be (caution); out leads.
     * Distinct counts (2 out, 1 low) so a row reading the other count fails.
     */
    public function test_attention_names_out_then_low(): void
    {
        $location = $this->taggedLocation($this->alpha['franchise']);

        $low = $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->setOnHand($low, $location, 3);

        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertSame([
            ['tone' => 'bad', 'text' => '2 items out of stock'],
            ['tone' => 'caution', 'text' => '1 item low'],
        ], $payload['data']['attention']);
    }

    public function test_nothing_low_or_out_needs_nobody(): void
    {
        $in = $this->taggedItem($this->alpha['franchise'], ['reorder_at' => '4.000']);
        $this->setOnHand($in, $this->taggedLocation($this->alpha['franchise']), 10);

        $payload = app(InventoryGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertFalse($payload['data']['empty']);
        $this->assertArrayNotHasKey('attention', $payload['data']);
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
     * Stock value follows inventory.financials (Jose, 2026-10-05: money is
     * the role's "can see financials"): a figure for a reader who holds it,
     * nothing at all in the payload for one who does not.
     */
    public function test_stock_value_is_a_figure_only_with_financials(): void
    {
        $location = $this->taggedLocation($this->alpha['franchise']);
        $item = $this->taggedItem($this->alpha['franchise'], [
            'last_cost_minor' => 250,
        ]);
        $this->setOnHand($item, $location, 2);

        $money = $this->userWithPermissions(['inventory.view', 'inventory.financials'], 'money@alpha.test');
        $viewer = $this->userWithPermissions(['inventory.view'], 'viewer@alpha.test');

        $with = app(InventoryGlance::class)->resolve($money, DashboardPeriod::for($money), [])->toArray();
        $this->assertSame(
            ['key' => 'stock_value', 'label' => 'stock value', 'value' => 500, 'previous' => null, 'currency' => 'USD'],
            end($with['data']['figures']),
        );

        $without = app(InventoryGlance::class)->resolve($viewer, DashboardPeriod::for($viewer), [])->toArray();
        $this->assertSame(1, $without['data']['slices'][0]['total'], 'the stock is in this box');
        $json = json_encode($without);
        $this->assertStringNotContainsString('currency', $json);
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
