<?php

namespace Tests\Feature\Live;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\InventoryIssue;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Models\Invitation;
use App\Models\PtoRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleServiceVisit;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Tenancy\TenantContext;
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

    public function test_vehicles_and_a_service_visit_as_its_vehicle(): void
    {
        $this->assertScoped('vehicles', ['fleet_management.view'],
            fn (Franchise $f) => Vehicle::factory()->create(['company_id' => $f->company_id, 'franchise_id' => $f->id])->id);

        // A visit logged on a van is the van's change.
        $t = $this->provisionTenant('Gamma');
        $van = $this->inTenant($t['org'], fn () => Vehicle::factory()->create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id]));
        $since = $this->cursor($t['admin'], 'vehicles');
        $this->inTenant($t['org'], fn () => VehicleServiceVisit::factory()->create(['vehicle_id' => $van->id]));
        $this->assertSame([$van->id], $this->ids($t['admin'], 'vehicles', $since));
    }

    /** People are assigned, not filed: the feed asks HR's own "may you see this person". */
    public function test_people_keep_to_the_people_a_reader_may_see(): void
    {
        $this->assertScoped('people', ['hr.view'], function (Franchise $f) {
            $person = User::factory()->create(['name' => 'Tech '.bin2hex(random_bytes(2))]);
            Assignment::create(['organization_id' => $person->organization_id, 'user_id' => $person->id, 'assigned_franchise_id' => $f->id]);
            // Time off asked for is the person's change too.
            PtoRequest::query()->create(['organization_id' => $person->organization_id, 'user_id' => $person->id,
                'status' => 'pending', 'total' => 1, 'days' => [['date' => '2026-11-20', 'portion' => 1]]]);

            return $person->id;
        });
    }

    public function test_time_off_reaches_a_work_hours_reader_and_a_sign_in_is_not_news(): void
    {
        $t = $this->provisionTenant('Alpha');
        $hours = $this->userWithRole($t, 'Hours', ['work_hours.view']);
        $since = $this->cursor($hours, 'people');
        $this->inTenant($t['org'], fn () => PtoRequest::query()->create(['organization_id' => $t['org']->id,
            'user_id' => $hours->id, 'status' => 'pending', 'total' => 1, 'days' => [['date' => '2026-11-20', 'portion' => 1]]]));
        $this->assertSame([$hours->id], $this->ids($hours, 'people', $since));

        $since = $this->cursor($hours, 'people');
        $this->inTenant($t['org'], fn () => User::query()->find($t['admin']->id)
            ->forceFill(['remember_token' => 'x'.bin2hex(random_bytes(8)), 'preferences' => ['jobs.sort' => 'date']])->save());
        $this->assertSame([], $this->ids($hours, 'people', $since), 'saved filters and sign-ins are not news');
        $this->inTenant($t['org'], fn () => User::query()->find($t['admin']->id)->update(['name' => 'Renamed Admin']));
        $this->assertSame([$t['admin']->id], $this->ids($hours, 'people', $since));
    }

    public function test_invitations_are_filed_where_they_assign(): void
    {
        $this->assertScoped('hr_invitations', ['hr.view'], fn (Franchise $f) => Invitation::query()->forceCreate([
            'organization_id' => app(TenantContext::class)->id(), 'email' => bin2hex(random_bytes(3)).'@example.test',
            'assigned_franchise_id' => $f->id, 'token_hash' => hash('sha256', random_bytes(8)),
            'sent_at' => now(), 'expires_at' => now()->addWeek(),
        ])->id);
    }
}
