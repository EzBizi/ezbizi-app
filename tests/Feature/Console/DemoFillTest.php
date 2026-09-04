<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleServiceVisit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `demo:fill` is the only command here written to run against the production
 * database on purpose, so the things worth pinning are the ones that would hurt:
 * that it refuses anything but the demo organization, and that running it twice
 * does not double the tenant's data.
 */
class DemoFillTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DEMO_ID = '01a03e4f-f9a1-7012-9515-96bc9260d2f8';

    private const DEMO_NAME = 'EzBizi Demo';

    /**
     * The demo tenant, built directly rather than through provisionTenant().
     *
     * The id is part of the guard, so the fixture has to hold it from the start.
     * Repointing a provisioned organization afterwards is not open to us: rows
     * already reference it and MySQL refuses the update outright — 1451, on
     * assignments_assigned_organization_id_foreign. So this creates only what
     * demo:fill reads: an organization, a company to own a franchise, a
     * franchise to own the areas, and two active people.
     *
     * @return array{org: Organization, company: Company, franchise: Franchise}
     */
    private function demoTenant(string $name = self::DEMO_NAME, bool $isDemo = true): array
    {
        // `id` is not fillable, so create() would drop it silently - and that
        // is not a small miss here, because the id is half of what demo:fill
        // checks. Set it on the instance instead.
        $org = new Organization([
            'name' => $name,
            'slug' => 'ezbizi-demo',
            'reporting_currency' => 'USD',
            'franchise_label' => 'Franchise',
            'is_active' => true,
            'is_demo' => $isDemo,
        ]);
        $org->id = self::DEMO_ID;
        $org->save();

        return app(TenantContext::class)->run($org, function () use ($org) {
            $company = Company::query()->create([
                'organization_id' => $org->id,
                'name' => 'Demo Co',
                'is_active' => true,
            ]);

            $franchise = Franchise::query()->create([
                'organization_id' => $org->id,
                'company_id' => $company->id,
                'name' => 'Demo — Orlando',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            foreach (['Ada Vance', 'Ivan Petrossian'] as $i => $person) {
                User::query()->create([
                    'organization_id' => $org->id,
                    'name' => $person,
                    'email' => 'demo'.$i.'@example.test',
                    'password' => 'a-real-password',
                    'is_active' => true,
                ]);
            }

            return ['org' => $org, 'company' => $company, 'franchise' => $franchise];
        });
    }

    public function test_it_refuses_an_organization_that_is_not_the_demo_one(): void
    {
        $this->provisionTenant('Northgate Garage Doors');

        $this->artisan('demo:fill')
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();
    }

    public function test_it_refuses_the_right_id_under_the_wrong_name(): void
    {
        // The case the name half of the guard exists for: an id recycled onto,
        // or a demo organization renamed into, something real.
        $this->demoTenant('A1-Group');

        $this->artisan('demo:fill')
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();

        $this->assertSame(0, ServiceArea::query()->withoutGlobalScopes()->count());
    }

    public function test_it_refuses_when_is_demo_is_not_set(): void
    {
        // Right id, right name, but the column that actually means "this tenant
        // holds invented data" says no. That is the one to believe.
        $this->demoTenant(self::DEMO_NAME, isDemo: false);

        $this->artisan('demo:fill')
            ->expectsOutputToContain('is_demo UNSET')
            ->assertFailed();

        $this->assertSame(0, ServiceArea::query()->withoutGlobalScopes()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->demoTenant();

        $this->artisan('demo:fill', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, ServiceArea::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, VehicleAssignment::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, TimeClockShift::query()->withoutGlobalScopes()->count());
    }

    public function test_it_fills_scheduling_fleet_and_the_time_clock(): void
    {
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');
        $this->makeVehicle($tenant, 'V-00002');

        $this->artisan('demo:fill')->assertSuccessful();

        // Scheduling: an area with zips and a working week is what turns the
        // screen from a search box over nothing into something photographable.
        $this->assertSame(3, ServiceArea::query()->withoutGlobalScopes()->count());
        $this->assertSame(10, ServiceAreaZip::query()->withoutGlobalScopes()->count());
        $this->assertSame(15, ServiceAreaSlot::query()->withoutGlobalScopes()->count());

        // Fleet: every vehicle has a current holder, which is the line that read
        // "Nobody has the keys" on all five of them.
        $this->assertSame(2, VehicleAssignment::query()->withoutGlobalScopes()->where('open', 1)->count());
        $this->assertSame(2, VehicleServiceVisit::query()->withoutGlobalScopes()->count());

        $this->assertGreaterThan(0, TimeClockShift::query()->withoutGlobalScopes()->count());
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');

        $this->artisan('demo:fill')->assertSuccessful();
        $before = $this->counts();

        $this->artisan('demo:fill')->assertSuccessful();

        $this->assertSame($before, $this->counts(), 'a second run must be a no-op, not doubles');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'areas' => ServiceArea::query()->withoutGlobalScopes()->count(),
            'zips' => ServiceAreaZip::query()->withoutGlobalScopes()->count(),
            'slots' => ServiceAreaSlot::query()->withoutGlobalScopes()->count(),
            'assignments' => VehicleAssignment::query()->withoutGlobalScopes()->count(),
            'visits' => VehicleServiceVisit::query()->withoutGlobalScopes()->count(),
            'shifts' => TimeClockShift::query()->withoutGlobalScopes()->count(),
        ];
    }

    /** @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant */
    private function makeVehicle(array $tenant, string $number): Vehicle
    {
        return app(TenantContext::class)->run($tenant['org'], fn () => Vehicle::query()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'number' => $number,
            'label' => "Van {$number}",
            'year' => 2021,
            'make' => 'Ford',
            'model' => 'Transit 250',
            'plate' => 'DEMO-'.substr($number, -3),
            'vin' => str_pad($number, 17, 'X'),
            'colour' => 'White',
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'status' => 'active',
        ]));
    }
}
