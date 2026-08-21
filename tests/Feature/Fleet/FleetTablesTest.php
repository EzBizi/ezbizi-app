<?php

namespace Tests\Feature\Fleet;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FleetTablesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_fleet_tables_exist(): void
    {
        foreach ([
            'vehicles',
            'vehicle_ownership_periods',
            'vehicle_insurance_policies',
            'vehicle_assignments',
            'vehicle_service_visits',
            'vehicle_payments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_two_open_ownership_periods_on_one_vehicle_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        $row = $this->ownershipRow($tenant, $vehicleId, endedOn: null);
        DB::table('vehicle_ownership_periods')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('vehicle_ownership_periods')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
        ]);
    }

    public function test_a_closed_ownership_period_allows_a_new_open_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        DB::table('vehicle_ownership_periods')->insert(
            $this->ownershipRow($tenant, $vehicleId, endedOn: '2024-01-01'),
        );
        DB::table('vehicle_ownership_periods')->insert(
            $this->ownershipRow($tenant, $vehicleId, endedOn: null),
        );

        $this->assertSame(2, DB::table('vehicle_ownership_periods')->where('vehicle_id', $vehicleId)->count());
    }

    public function test_two_open_insurance_policies_on_one_vehicle_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        $row = $this->insuranceRow($tenant, $vehicleId, endedOn: null);
        DB::table('vehicle_insurance_policies')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('vehicle_insurance_policies')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
        ]);
    }

    public function test_a_closed_insurance_policy_allows_a_new_open_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        DB::table('vehicle_insurance_policies')->insert(
            $this->insuranceRow($tenant, $vehicleId, endedOn: '2024-06-01'),
        );
        DB::table('vehicle_insurance_policies')->insert(
            $this->insuranceRow($tenant, $vehicleId, endedOn: null),
        );

        $this->assertSame(2, DB::table('vehicle_insurance_policies')->where('vehicle_id', $vehicleId)->count());
    }

    public function test_two_open_assignments_on_one_vehicle_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        $row = $this->assignmentRow($tenant, $vehicleId, releasedAt: null);
        DB::table('vehicle_assignments')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('vehicle_assignments')->insert([
            ...$row,
            'id' => (string) Str::uuid(),
        ]);
    }

    public function test_a_released_assignment_allows_a_new_open_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicleId = $this->insertVehicle($tenant);

        DB::table('vehicle_assignments')->insert(
            $this->assignmentRow($tenant, $vehicleId, releasedAt: '2024-03-01 12:00:00'),
        );
        DB::table('vehicle_assignments')->insert(
            $this->assignmentRow($tenant, $vehicleId, releasedAt: null),
        );

        $this->assertSame(2, DB::table('vehicle_assignments')->where('vehicle_id', $vehicleId)->count());
    }

    public function test_several_null_vins_in_one_organization_are_allowed(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        DB::table('vehicles')->insert($this->vehicleRow($tenant, number: 'V-00001', vin: null));
        DB::table('vehicles')->insert($this->vehicleRow($tenant, number: 'V-00002', vin: null));

        $this->assertSame(2, DB::table('vehicles')->where('organization_id', $tenant['org']->id)->count());
    }

    /** @param  array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise}  $tenant */
    private function insertVehicle(array $tenant): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert($this->vehicleRow($tenant, number: 'V-00001', vin: null, id: $id));

        return $id;
    }

    /**
     * @param  array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise}  $tenant
     * @return array<string, mixed>
     */
    private function vehicleRow(array $tenant, string $number, ?string $vin, ?string $id = null): array
    {
        $now = now();

        return [
            'id' => $id ?? (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'number' => $number,
            'label' => 'Unidentified vehicle',
            'year' => null,
            'make' => null,
            'model' => null,
            'plate' => null,
            'vin' => $vin,
            'colour' => null,
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'odometer_miles' => null,
            'next_service_on' => null,
            'next_service_miles' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @return array<string, mixed>
     */
    private function ownershipRow(array $tenant, string $vehicleId, ?string $endedOn): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'vehicle_id' => $vehicleId,
            'kind' => 'owned',
            'counterparty' => 'Dealer',
            'started_on' => '2023-01-01',
            'ended_on' => $endedOn,
            'ended_reason' => $endedOn === null ? null : 'sold',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @return array<string, mixed>
     */
    private function insuranceRow(array $tenant, string $vehicleId, ?string $endedOn): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'vehicle_id' => $vehicleId,
            'carrier' => 'State Farm',
            'policy_number' => 'P-1',
            'coverage' => null,
            'started_on' => '2023-01-01',
            'expires_on' => null,
            'ended_on' => $endedOn,
            'premium_cents' => null,
            'premium_period' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{org: \App\Models\Organization, admin: \App\Models\User}  $tenant
     * @return array<string, mixed>
     */
    private function assignmentRow(array $tenant, string $vehicleId, ?string $releasedAt): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'vehicle_id' => $vehicleId,
            'user_id' => $tenant['admin']->id,
            'assigned_at' => '2023-01-01 08:00:00',
            'released_at' => $releasedAt,
            'note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
