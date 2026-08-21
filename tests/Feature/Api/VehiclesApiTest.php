<?php

namespace Tests\Feature\Api;

use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use App\Models\VehiclePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Create a vehicle with its first ownership period; show strips money without financials.
 */
class VehiclesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_admin_creates_vehicle_with_ownership_and_refuses_duplicate_vin_or_missing_ownership(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $payload = [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'year' => 2022,
            'make' => 'Ford',
            'model' => 'Transit',
            'plate' => 'ABC-1234',
            'vin' => '1FTBW2CM5NKA00001',
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'ownership' => [
                'kind' => 'owned',
                'counterparty' => 'Dealer Co',
                'purchase_price_cents' => 2_500_000,
            ],
        ];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['id']);
        $this->assertSame('2022 Ford Transit · ABC-1234', $created['label']);
        $this->assertStringStartsWith('V-', $created['number']);
        $this->assertSame('owned', $created['ownership']['kind']);
        $this->assertSame('Dealer Co', $created['ownership']['counterparty']);

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, Vehicle::query()->count());
            $this->assertSame(1, VehicleOwnershipPeriod::query()->count());
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vin'])
            ->assertJsonFragment(['vin' => ['This organization already has a vehicle with that VIN.']]);

        $withoutOwnership = $payload;
        unset($withoutOwnership['ownership']);
        $withoutOwnership['vin'] = '1FTBW2CM5NKA00002';

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles', $withoutOwnership)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ownership'])
            ->assertJsonFragment([
                'ownership' => ['A vehicle has to be owned or rented. Say which, then save.'],
            ]);
    }

    public function test_show_strips_money_and_payments_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2021,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'XYZ-9999',
            ]);

            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'counterparty' => 'Seller',
                'purchase_price_cents' => 1_200_000,
            ]);

            VehiclePayment::factory()->create([
                'vehicle_id' => $vehicle->id,
                'amount_cents' => 50_000,
            ]);

            return $vehicle;
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'fleet_management.view',
            'fleet_management.financials',
        ]);

        $stripped = $this->signedInAs($viewer)
            ->getJson('/api/v1/vehicles/'.$vehicle->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('payments', $stripped);
        $this->assertArrayNotHasKey('purchase_price_cents', $stripped);
        $this->assertArrayNotHasKey('purchase_price_cents', $stripped['ownership'] ?? []);
        if (isset($stripped['ownership_periods'][0])) {
            $this->assertArrayNotHasKey('purchase_price_cents', $stripped['ownership_periods'][0]);
        }

        $kept = $this->signedInAs($money)
            ->getJson('/api/v1/vehicles/'.$vehicle->id)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('payments', $kept);
        $this->assertNotEmpty($kept['payments']);
        $this->assertSame(1_200_000, $kept['ownership']['purchase_price_cents']);
    }
}
