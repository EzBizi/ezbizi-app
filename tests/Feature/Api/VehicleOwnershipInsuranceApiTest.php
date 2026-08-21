<?php

namespace Tests\Feature\Api;

use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleOwnershipPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Starting ownership and insurance periods by closing the open row (spec §6.1, §6.2).
 */
class VehicleOwnershipInsuranceApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_owned_to_rented_closes_owned_period(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'counterparty' => 'Dealer Co',
                'started_on' => now()->subMonth()->toDateString(),
                'ended_on' => null,
            ]);

            return $vehicle;
        });

        $response = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/ownership', [
                'kind' => 'rented',
                'counterparty' => 'Rentals Inc',
                'started_on' => now()->toDateString(),
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('rented', $response['ownership']['kind']);
        $this->assertSame('Rentals Inc', $response['ownership']['counterparty']);
        $this->assertNull($response['ownership']['ended_on']);

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $periods = VehicleOwnershipPeriod::query()
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('started_on')
                ->get();

            $this->assertCount(2, $periods);
            $this->assertSame('owned', $periods[0]->kind->value);
            $this->assertSame(now()->toDateString(), $periods[0]->ended_on?->toDateString());
            $this->assertSame('replaced', $periods[0]->ended_reason->value);
            $this->assertSame('rented', $periods[1]->kind->value);
            $this->assertNull($periods[1]->ended_on);
            $this->assertSame(1, $vehicle->fresh()->ownershipPeriods()->whereNull('ended_on')->count());
        });
    }

    public function test_two_insurance_posts_leave_one_open_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $vehicle->id,
                'carrier' => 'Old Mutual',
                'policy_number' => 'OLD-1',
                'started_on' => now()->subYear()->toDateString(),
                'ended_on' => null,
            ]);

            return $vehicle;
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/insurance', [
                'carrier' => 'New Mutual',
                'policy_number' => 'NEW-1',
                'coverage' => 'Full',
                'started_on' => now()->toDateString(),
                'expires_on' => now()->addYear()->toDateString(),
            ])
            ->assertOk();

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/insurance', [
                'carrier' => 'Third Mutual',
                'policy_number' => 'NEW-2',
                'started_on' => now()->toDateString(),
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $policies = VehicleInsurancePolicy::query()
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('created_at')
                ->get();

            $this->assertCount(3, $policies);
            $this->assertSame(1, $policies->whereNull('ended_on')->count());
            $this->assertSame('Third Mutual', $vehicle->fresh()->openInsurance()?->carrier);
            $this->assertNotNull($policies[0]->ended_on);
            $this->assertNotNull($policies[1]->ended_on);
            $this->assertNull($policies[2]->ended_on);
        });
    }

    public function test_ownership_money_ignored_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $editor = $this->userWithRole($tenant, 'Editor', [
            'fleet_management.view',
            'fleet_management.edit',
        ]);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'counterparty' => 'Dealer Co',
                'ended_on' => null,
            ]);

            return $vehicle;
        });

        $response = $this->signedInAs($editor)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/ownership', [
                'kind' => 'owned',
                'counterparty' => 'New Dealer',
                'started_on' => now()->toDateString(),
                'purchase_price_cents' => 3_000_000,
                'lienholder' => 'Big Bank',
            ]);

        // Without financials: accept the period, do not 403 on money keys.
        $response->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('purchase_price_cents', $data['ownership'] ?? []);
        $this->assertArrayNotHasKey('lienholder', $data['ownership'] ?? []);

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $open = $vehicle->fresh()->openOwnership();
            $this->assertSame('New Dealer', $open?->counterparty);
            $this->assertNull($open?->purchase_price_cents);
            $this->assertNull($open?->lienholder);
        });
    }
}
