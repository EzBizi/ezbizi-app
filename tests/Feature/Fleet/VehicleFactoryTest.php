<?php

namespace Tests\Feature\Fleet;

use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use App\Support\Fleet\VehicleOwnershipKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class VehicleFactoryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_factory_stamps_organization_and_derives_label(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2021,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'KGN-4412',
            ]);
        });

        $this->assertSame($tenant['org']->id, $vehicle->organization_id);
        $this->assertSame('2021 Ford Transit · KGN-4412', $vehicle->label);
    }

    public function test_open_ownership_returns_the_unended_period(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => VehicleOwnershipKind::Owned,
                'started_on' => '2022-01-01',
                'ended_on' => '2023-06-01',
                'ended_reason' => 'sold',
            ]);

            $open = VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => VehicleOwnershipKind::Owned,
                'started_on' => '2023-06-02',
                'ended_on' => null,
            ]);

            $found = $vehicle->openOwnership();

            $this->assertNotNull($found);
            $this->assertSame($open->id, $found->id);
            $this->assertNull($found->ended_on);
        });
    }

    public function test_empty_vin_is_stored_as_null_and_a_new_vehicle_is_empty(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'vin' => '',
            ]);

            $this->assertNull($vehicle->vin);
            $this->assertTrue($vehicle->isEmpty());
        });
    }
}
