<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
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
 * Snapshot pie of VehicleStatus under InventoryVisibility. Empty-at-zero once any
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
                'role_id'         => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
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
        $this->assertSame('No vehicles to show.', $payload['data']['sentence']);
        $this->assertArrayNotHasKey('revenue', $payload['data']);
        $this->assertArrayNotHasKey('amount_minor', $payload['data']);
    }

    public function test_the_pie_ignores_the_dashboard_period(): void
    {
        $this->inTenant(fn () => Vehicle::factory()->create([
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $this->alpha['franchise']->id,
            'status' => VehicleStatus::Active,
            'next_service_on' => now()->subDay()->toDateString(),
        ]));

        $this->inTenant(fn () => VehicleInsurancePolicy::factory()->create([
            'vehicle_id' => Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
            ])->id,
            'expires_on' => now()->addDay()->toDateString(),
            'ended_on' => null,
        ]));

        $week = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $year = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertSame($week['data']['slices'], $year['data']['slices']);
        $this->assertSame($week['data']['sentences'], $year['data']['sentences']);
        $this->assertContains('1 vehicle needs service soon.', $week['data']['sentences']);
        $this->assertContains('1 vehicle has insurance due soon.', $week['data']['sentences']);
    }

    /**
     * The figures: every vehicle in reach first (sold ones included, as the
     * pie counts them), then service and insurance due. Snapshots, so no
     * previous.
     */
    public function test_the_figures_lead_with_the_vehicles_in_reach(): void
    {
        $this->taggedVehicle($this->alpha['franchise']);
        $this->taggedVehicle($this->alpha['franchise'], VehicleStatus::Sold);

        $this->inTenant(function () {
            Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
                'next_service_on' => now()->subDay()->toDateString(),
            ]);

            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => Vehicle::factory()->create([
                    'company_id' => $this->alpha['company']->id,
                    'franchise_id' => $this->alpha['franchise']->id,
                    'status' => VehicleStatus::Active,
                ])->id,
                'expires_on' => now()->addDay()->toDateString(),
                'ended_on' => null,
            ]);
        });

        $figures = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data']['figures'];

        $this->assertSame([
            ['key' => 'vehicles', 'label' => 'vehicles', 'value' => 4, 'previous' => null],
            ['key' => 'service_due', 'label' => 'need service soon', 'value' => 1, 'previous' => null],
            ['key' => 'insurance_due', 'label' => 'insurance due soon', 'value' => 1, 'previous' => null],
        ], $figures);
    }

    public function test_service_due_matches_the_vehicle_list(): void
    {
        $this->inTenant(function () {
            Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
                'next_service_on' => now()->subDay()->toDateString(),
            ]);

            Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
                'next_service_on' => now()->addDays(60)->toDateString(),
            ]);

            Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Sold,
                'next_service_on' => now()->subDay()->toDateString(),
            ]);
        });

        $payload = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertContains('1 vehicle needs service soon.', $payload['data']['sentences']);
        $this->assertArrayNotHasKey('revenue', $payload['data']);
        $this->assertArrayNotHasKey('amount_minor', $payload['data']);
    }

    public function test_insurance_due_counts_open_policies_within_thirty_days(): void
    {
        $this->inTenant(function () {
            $openVehicle = Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
            ]);

            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $openVehicle->id,
                'expires_on' => now()->addDay()->toDateString(),
                'ended_on' => null,
            ]);

            $endedVehicle = Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
            ]);

            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $endedVehicle->id,
                'expires_on' => now()->addDay()->toDateString(),
                'ended_on' => now()->subDay()->toDateString(),
            ]);
        });

        $payload = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertContains('1 vehicle has insurance due soon.', $payload['data']['sentences']);
        $this->assertArrayNotHasKey('revenue', $payload['data']);
        $this->assertArrayNotHasKey('amount_minor', $payload['data']);
    }

    /**
     * Attention: overdue is bad and comes first, inside thirty days is
     * caution, at most three rows. Two overdue vans — one by date, one by
     * mileage — so a broken branch changes the number. The fourth row
     * (insurance soon) is real and must be dropped by the cap.
     */
    public function test_attention_puts_overdue_first_and_keeps_three(): void
    {
        $this->inTenant(function () {
            $van = fn (array $attributes = []) => Vehicle::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'status' => VehicleStatus::Active,
                ...$attributes,
            ]);

            $van(['next_service_on' => now()->subDay()->toDateString()]);
            $van(['odometer_miles' => 50000, 'next_service_miles' => 49000]);
            $van(['next_service_on' => now()->addDays(10)->toDateString()]);

            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $van()->id,
                'expires_on' => now()->subDay()->toDateString(),
            ]);
            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $van()->id,
                'expires_on' => now()->addDays(10)->toDateString(),
            ]);
        });

        $data = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data'];

        $this->assertSame([
            ['tone' => 'bad', 'text' => '2 vehicles are overdue for service.'],
            ['tone' => 'bad', 'text' => '1 vehicle has expired insurance.'],
            ['tone' => 'caution', 'text' => '1 vehicle needs service within 30 days.'],
        ], $data['attention']);

        // The figures still count every due vehicle, overdue included, so the
        // rows are a split of them and not a second opinion.
        $figures = collect($data['figures'])->keyBy('key');
        $this->assertSame(3, $figures['service_due']['value']);
        $this->assertSame(2, $figures['insurance_due']['value']);
    }

    public function test_no_attention_when_nothing_is_due(): void
    {
        // Control: a vehicle exists, so this is the non-empty branch.
        $this->taggedVehicle($this->alpha['franchise']);

        $data = app(FleetGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data'];

        $this->assertFalse($data['empty']);
        $this->assertArrayNotHasKey('attention', $data);
    }
}
