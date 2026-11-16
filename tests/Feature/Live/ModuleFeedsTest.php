<?php

namespace Tests\Feature\Live;

use App\Models\Franchise;
use App\Models\InventoryIssue;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The change feed beyond the first five types (Jose, 2026-11-16: "This
 * should go tbh for every module in the app"). Each module's records reach
 * the feed from a plain model write, and keep to the reader's permission and
 * reach: own branch yes, a sibling branch no, the admin both, no permission
 * nothing.
 */
class ModuleFeedsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function ids(User $user, string $types, int $since): array
    {
        $this->signedInAs($user);
        $ids = array_column($this->getJson('/api/v1/changes?types='.$types.'&since='.$since)
            ->assertOk()->json('data.changes'), 'id');
        sort($ids);

        return $ids;
    }

    private function cursor(User $user, string $types): int
    {
        $this->signedInAs($user);

        return $this->getJson('/api/v1/changes?types='.$types)->assertOk()->json('data.cursor');
    }

    private function sorted(string ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * Writes one record at the tenant's own branch and one at a sibling, and
     * reads the feed as a branch user, the admin and somebody without the
     * permission.
     *
     * @param  callable(Franchise): string  $write  returns the record id
     */
    private function assertScoped(string $type, array $permissions, callable $write): void
    {
        $t = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $sibling = $this->extraFranchise($t, 'Alpha F2');
        $branch = $this->userWithRole($t, 'Branch', $permissions, onlyAt: $t['franchise']);
        $without = $this->userWithRole($t, 'Without', ['leads.view']);

        $since = $this->cursor($t['admin'], $type);
        $mine = $this->inTenant($t['org'], fn () => $write($t['franchise']));
        $theirs = $this->inTenant($t['org'], fn () => $write($sibling));
        $this->inTenant($beta['org'], fn () => $write($beta['franchise']));

        $this->assertSame([$mine], $this->ids($branch, $type, $since), 'own branch only');
        $this->assertSame($this->sorted($mine, $theirs), $this->ids($t['admin'], $type, $since), 'admin: both, never Beta');
        $this->assertSame([], $this->ids($without, $type, $since), 'no permission: nothing');
    }

    public function test_inventory_orders_issues_and_sales(): void
    {
        $this->assertScoped('inventory_orders', ['inventory.view', 'inventory.orders.view'], function (Franchise $f) {
            $vendor = InventoryVendor::create(['company_id' => $f->company_id, 'franchise_id' => $f->id, 'name' => 'Supply '.bin2hex(random_bytes(2))]);

            return InventoryPurchaseOrder::create([
                'company_id' => $f->company_id, 'franchise_id' => $f->id, 'vendor_id' => $vendor->id,
                'number' => 'PO-'.bin2hex(random_bytes(3)), 'status' => InventoryPurchaseOrderStatus::Draft,
            ])->id;
        });
    }

    public function test_an_issue_is_filed_where_its_location_is(): void
    {
        $this->assertScoped('inventory_orders', ['inventory.view', 'inventory.orders.view'], function (Franchise $f) {
            $at = InventoryLocation::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id]);

            return InventoryIssue::create([
                'location_id' => $at->id, 'note' => 'used on the van',
                'number' => 'IS-'.bin2hex(random_bytes(4)), 'issued_on' => '2026-11-16',
            ])->id;
        });
    }

    public function test_inventory_locations(): void
    {
        $this->assertScoped('inventory_locations', ['inventory.view', 'inventory.locations.view'],
            fn (Franchise $f) => InventoryLocation::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id])->id);
    }
}
