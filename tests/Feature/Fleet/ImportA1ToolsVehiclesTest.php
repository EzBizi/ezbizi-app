<?php

namespace Tests\Feature\Fleet;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Artisan;
use App\Support\Fleet\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The A1 Tools carry-over (`fleet:import-a1-tools`).
 *
 * Covers the three things that would quietly corrupt the register: a deleted
 * row arriving in service, a required `started_on` invented out of nothing,
 * and a second run minting duplicates.
 */
class ImportA1ToolsVehiclesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function export(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'a1v').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    private function row(array $overrides = []): array
    {
        return [
            'label' => '2022 Ford Transit',
            'vin' => '1FTNE2CM5HKA'.str_pad((string) random_int(0, 99999), 5, '0'),
            'license_plate' => 'ABC-1234',
            'plate_state' => 'CT',
            'year' => 2022,
            'make' => 'Ford',
            'model' => 'Transit',
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'status' => 'active',
            'ownership' => 'owned',
            'is_active' => 1,
            'created_at' => '2026-08-26 13:01:20',
            ...$overrides,
        ];
    }

    public function test_a_vehicle_lands_with_its_place_plate_state_and_an_ownership_period(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'registered_owner' => 'A-1 Chimney LLC',
            'purchased_from' => 'Hartford Ford',
            'insurance_carrier' => 'Travelers',
            'insurance_agent' => 'Dana Reed',
        ])]);

        $this->artisan('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
        ])->assertSuccessful();

        $vehicle = $this->inTenant(
            $tenant['org'],
            fn () => Vehicle::with(['ownershipPeriods', 'insurancePolicies'])->firstOrFail(),
        );

        $this->assertSame('CT', $vehicle->plate_state);
        $this->assertSame($tenant['franchise']->id, $vehicle->franchise_id);
        $this->assertStringStartsWith('V-', $vehicle->number);
        $this->assertSame(VehicleStatus::Active, $vehicle->status);

        $period = $vehicle->ownershipPeriods->firstOrFail();
        $this->assertSame('A-1 Chimney LLC', $period->registered_owner);
        $this->assertSame('Hartford Ford', $period->counterparty);
        // No purchase_date on the source row, so the row's own creation date
        // stands in rather than today or a null the column will not take.
        $this->assertSame('2026-08-26', $period->started_on->toDateString());

        $policy = $vehicle->insurancePolicies->firstOrFail();
        $this->assertSame('Travelers', $policy->carrier);
        $this->assertSame('Dana Reed', $policy->agent);
    }

    public function test_a_row_deleted_in_a1_tools_does_not_arrive_in_service(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'is_active' => 0,
            'status' => 'active',
        ])]);

        $this->artisan('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
        ])->assertSuccessful();

        $vehicle = $this->inTenant($tenant['org'], fn () => Vehicle::firstOrFail());

        $this->assertSame(VehicleStatus::OutOfService, $vehicle->status);
    }

    public function test_running_it_twice_does_not_mint_a_second_row(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'vin' => '1FTNE2CM5HKA12345',
        ])]);

        foreach ([1, 2] as $_) {
            $this->artisan('fleet:import-a1-tools', [
                'file' => $file,
                '--organization' => $tenant['org']->name,
                '--company' => $tenant['company']->name,
            ])->assertSuccessful();
        }

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => Vehicle::count()));
    }

    public function test_the_typed_absence_na_does_not_become_a_plate_or_a_lienholder(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'license_plate' => 'N/A',
            'lienholder' => 'NA',
            'purchased_from' => 'NA',
        ])]);

        $this->artisan('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
        ])->assertSuccessful();

        $vehicle = $this->inTenant(
            $tenant['org'],
            fn () => Vehicle::with('ownershipPeriods')->firstOrFail(),
        );

        $this->assertNull($vehicle->plate);
        $this->assertStringNotContainsString('N/A', $vehicle->label);

        $period = $vehicle->ownershipPeriods->firstOrFail();
        $this->assertNull($period->lienholder);
        $this->assertNull($period->counterparty);
    }

    /**
     * The first dry run against the real export printed A1 Tools' own stored
     * label, which already reads '... · NA'. That hid four '(NEW)' plates
     * the guard was not catching: a fixed row and a broken one looked the same.
     */
    public function test_a_van_awaiting_plates_arrives_without_new_as_its_plate(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'label' => '2026 Ford Transit Cargo Van · (NEW)',
            'year' => 2026,
            'make' => 'Ford',
            'model' => 'Transit Cargo Van',
            'license_plate' => '(NEW)',
        ])]);

        $this->artisan('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
        ])->assertSuccessful();

        $vehicle = $this->inTenant(
            $tenant['org'],
            fn () => Vehicle::query()->firstOrFail(),
        );

        $this->assertNull($vehicle->plate);
        $this->assertStringNotContainsString('NEW', $vehicle->label);
    }

    /**
     * Written with expectsOutputToContain first; it passed against the reverted
     * code, so it was proving nothing. Reading Artisan::output() directly is
     * blunter and actually fails.
     */
    public function test_the_dry_run_prints_the_label_it_would_store_not_the_source_one(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row([
            'franchise_name' => $tenant['franchise']->name,
            'label' => '2026 Ford Transit Cargo Van · NA',
            'year' => 2026,
            'make' => 'Ford',
            'model' => 'Transit Cargo Van',
            'license_plate' => 'NA',
        ])]);

        $code = Artisan::call('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2026 Ford Transit Cargo Van', $output);
        $this->assertStringNotContainsString('· NA', $output);
        $this->assertStringNotContainsString('NA →', $output);

        $this->assertSame(0, $this->inTenant(
            $tenant['org'],
            fn () => Vehicle::query()->count(),
        ));
    }

    public function test_a_place_that_is_not_under_this_company_is_refused_not_guessed(): void
    {
        $tenant = $this->provisionTenant('A1-Group');

        $file = $this->export([$this->row(['franchise_name' => 'Nowhere'])]);

        $this->artisan('fleet:import-a1-tools', [
            'file' => $file,
            '--organization' => $tenant['org']->name,
            '--company' => $tenant['company']->name,
        ])->assertSuccessful();

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => Vehicle::count()));
    }
}
