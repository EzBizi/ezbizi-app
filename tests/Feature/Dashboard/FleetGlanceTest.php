<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\FleetGlance;
use App\Support\Fleet\VehicleStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fleet Management dashboard glance (spec § Fleet — pie).
 *
 * Snapshot pie of VehicleStatus under VehicleVisibility. Empty-at-zero once any
 * vehicle exists. No money keys on this box.
 */
class FleetGlanceTest extends TestCase
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

    private function taggedVehicle(Franchise $franchise, VehicleStatus $status = VehicleStatus::Active): Vehicle
    {
        return $this->inTenant(fn () => Vehicle::factory()->create([
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $franchise->id,
            'status' => $status,
        ]));
    }

    public function test_a_branch_user_sees_only_vehicles_in_their_reach(): void
    {
        [$second, $branch] = $this->secondBranch();
        $this->taggedVehicle($this->alpha['franchise']);
        $this->taggedVehicle($this->alpha['franchise']);
        $this->taggedVehicle($second);

        $payload = app(FleetGlance::class)->resolve($branch, DashboardPeriod::for($branch), [])->toArray();
        $this->assertSame(1, array_sum(array_column($payload['data']['slices'], 'total')));
    }

    public function test_zero_vehicles_is_a_sentence_not_slices(): void
    {
        $payload = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No vehicles in reach.', $payload['data']['sentence']);
        $this->assertArrayNotHasKey('revenue', $payload['data']);
        $this->assertArrayNotHasKey('amount_minor', $payload['data']);
    }

    public function test_the_pie_ignores_the_dashboard_period(): void
    {
        $this->taggedVehicle($this->alpha['franchise']);

        $week = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $year = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }
}
