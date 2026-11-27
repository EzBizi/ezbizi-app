<?php

namespace Tests\Feature\Api;

use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use App\Models\VehiclePayment;
use App\Models\VehicleServiceVisit;
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

    public function test_create_ignores_ownership_money_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $creator = $this->userWithRole($tenant, 'Creator', [
            'fleet_management.view',
            'fleet_management.create',
            'fleet_management.edit',
        ]);

        $created = $this->signedInAs($creator)
            ->postJson('/api/v1/vehicles', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'year' => 2022,
                'make' => 'Ford',
                'model' => 'Transit',
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => [
                    'kind' => 'owned',
                    'counterparty' => 'Dealer Co',
                    'purchase_price_cents' => 2_500_000,
                    'lienholder' => 'Big Bank',
                    'loan_account' => 'LOAN-1',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Dealer Co', $created['ownership']['counterparty'] ?? null);
        $this->assertArrayNotHasKey('purchase_price_cents', $created['ownership'] ?? []);
        $this->assertArrayNotHasKey('lienholder', $created['ownership'] ?? []);
        $this->assertArrayNotHasKey('loan_account', $created['ownership'] ?? []);

        $this->inTenant($tenant['org'], function () use ($created) {
            $period = VehicleOwnershipPeriod::query()
                ->where('vehicle_id', $created['id'])
                ->first();
            $this->assertSame('Dealer Co', $period?->counterparty);
            $this->assertNull($period?->purchase_price_cents);
            $this->assertNull($period?->lienholder);
            $this->assertNull($period?->loan_account);
        });
    }

    public function test_http_buy_back_ignores_ownership_money_without_financials(): void
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
                'status' => 'sold',
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'ended_on' => now()->subDay()->toDateString(),
                'ended_reason' => 'sold',
            ]);

            return $vehicle;
        });

        $this->signedInAs($editor)
            ->patchJson('/api/v1/vehicles/'.$vehicle->id, [
                'status' => 'active',
                'ownership' => [
                    'kind' => 'owned',
                    'counterparty' => 'Auction',
                    'purchase_price_cents' => 1_500_000,
                    'loan_account' => 'HTTP-ACC-1',
                    'lienholder' => 'Big Bank',
                ],
            ])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $open = $vehicle->fresh()->openOwnership();
            $this->assertSame('owned', $open?->kind->value);
            $this->assertSame('Auction', $open?->counterparty);
            $this->assertNull($open?->purchase_price_cents);
            $this->assertNull($open?->loan_account);
            $this->assertNull($open?->lienholder);
        });
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

    /**
     * The number beside the row is the count of amber boxes on the card.
     *
     * Asserted through GET, not by calling missing(), so it proves the number
     * reaches the payload the list reads. Trim and notes are empty here and
     * must NOT count: a field many vans truly lack would keep the count above
     * zero for ever. And a mileage alone answers "next service".
     */
    public function test_missing_counts_only_expected_fields_and_one_for_no_service(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $full = [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'year' => 2022,
            'make' => 'Ford',
            'model' => 'Transit',
            'vin' => '1FTBR1C8XTKB00931',
            'plate' => 'ABC1234',
            'plate_state' => 'CT',
            'colour' => 'White',
            'odometer_miles' => 12000,
            'registration_expires_on' => '2027-03-01',
            'next_service_on' => '2026-12-01',
            'trim' => null,
            'notes' => null,
        ];

        [$complete, $gaps, $milesOnly] = $this->inTenant(
            $tenant['org'],
            fn () => [
                Vehicle::factory()->create($full),
                Vehicle::factory()->create([
                    ...$full,
                    'vin' => '1FTBR1C8XTKB00932',
                    'plate' => null,
                    'colour' => null,
                    'next_service_on' => null,
                    'next_service_miles' => null,
                ]),
                Vehicle::factory()->create([
                    ...$full,
                    'vin' => '1FTBR1C8XTKB00933',
                    'next_service_on' => null,
                    'next_service_miles' => 15000,
                ]),
            ],
        );

        $read = fn (Vehicle $v) => $this->signedInAs($admin)
            ->getJson('/api/v1/vehicles/'.$v->id)
            ->assertOk()
            ->json('data.missing');

        // Every expected field filled, trim and notes empty: nothing owed.
        $this->assertSame(0, $read($complete));
        // Plate, colour, and no next service of either kind.
        $this->assertSame(3, $read($gaps));
        // A mileage with no date is an answer, not a gap.
        $this->assertSame(0, $read($milesOnly));
    }

    public function test_sold_refuses_rental_and_closes_owned_period(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $rental = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'status' => 'active',
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'rented',
                'counterparty' => 'Lease Co',
            ]);

            return $vehicle;
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$rental->id, ['status' => 'sold'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status'])
            ->assertJsonFragment([
                'status' => ['Sold is for a vehicle the company owns. Return a rental instead.'],
            ]);

        $owned = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'status' => 'active',
            ]);
            VehicleOwnershipPeriod::factory()->create([
                'vehicle_id' => $vehicle->id,
                'kind' => 'owned',
                'counterparty' => 'Dealer Co',
            ]);

            return $vehicle;
        });

        $sold = $this->signedInAs($admin)
            ->patchJson('/api/v1/vehicles/'.$owned->id, ['status' => 'sold'])
            ->assertOk()
            ->json('data');

        $this->assertSame('sold', $sold['status']);
        $this->assertNull($sold['ownership']);
        $this->assertSame('sold', $sold['ownership_periods'][0]['ended_reason']);
        $this->assertSame(now()->toDateString(), $sold['ownership_periods'][0]['ended_on']);

        $this->inTenant($tenant['org'], function () use ($owned) {
            $period = VehicleOwnershipPeriod::query()
                ->where('vehicle_id', $owned->id)
                ->first();
            $this->assertNotNull($period->ended_on);
            $this->assertSame('sold', $period->ended_reason->value);
            $this->assertNull($owned->fresh()->openOwnership());
        });
    }

    public function test_delete_refuses_history_and_removes_empty_vehicle(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $withVisit = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
            VehicleServiceVisit::factory()->create([
                'vehicle_id' => $vehicle->id,
            ]);

            return $vehicle;
        });

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/vehicles/'.$withVisit->id)
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This vehicle has history, so it cannot be removed. Mark it sold or returned instead.',
            ]);

        $this->inTenant($tenant['org'], function () use ($withVisit) {
            $this->assertTrue(Vehicle::query()->whereKey($withVisit->id)->exists());
        });

        $empty = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/vehicles/'.$empty->id)
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () use ($empty) {
            $this->assertFalse(Vehicle::query()->whereKey($empty->id)->exists());
        });
    }

    public function test_admin_creates_a_vehicle_on_the_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles', [
                'year' => 2022,
                'make' => 'Ford',
                'model' => 'Transit',
                'plate' => 'ORG-1',
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => [
                    'kind' => 'owned',
                    'counterparty' => 'Dealer Co',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNull($created['company']);
        $this->assertNull($created['franchise']);
        $this->assertStringStartsWith('V-', $created['number']);

        $this->inTenant($tenant['org'], function () use ($created) {
            $row = Vehicle::query()->find($created['id']);
            $this->assertNull($row?->company_id);
            $this->assertNull($row?->franchise_id);
        });
    }

    public function test_organization_vehicle_cannot_carry_a_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/vehicles', [
                'franchise_id' => $tenant['franchise']->id,
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => ['kind' => 'owned'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['franchise_id']);
    }

    public function test_franchise_user_cannot_file_an_organization_vehicle(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['fleet_management.view', 'fleet_management.create'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($local)
            ->postJson('/api/v1/vehicles', [
                'body_type' => 'van',
                'fuel_type' => 'gas',
                'ownership' => ['kind' => 'owned'],
            ])
            ->assertForbidden();
    }

    /**
     * Through POST /vehicles, as the app creates one: the creation period is
     * not history, so a vehicle added by mistake can be removed. Control: an
     * ended period (sold) is history and refuses.
     */
    public function test_a_vehicle_added_in_the_app_can_be_removed_until_it_has_history(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $add = fn (string $vin) => $this->signedInAs($admin)->postJson('/api/v1/vehicles', [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'year' => 2024, 'make' => 'Ford', 'model' => 'Transit', 'vin' => $vin,
            'body_type' => 'van', 'fuel_type' => 'gas',
            'ownership' => ['kind' => 'owned'],
        ])->assertCreated()->json('data.id');

        $fresh = $add('1FTBW2CM5NKA00011');
        $this->signedInAs($admin)->deleteJson('/api/v1/vehicles/'.$fresh)->assertNoContent();

        $sold = $add('1FTBW2CM5NKA00012');
        $this->inTenant($tenant['org'], fn () => VehicleOwnershipPeriod::query()
            ->where('vehicle_id', $sold)->update(['ended_on' => now()->toDateString()]));
        $this->signedInAs($admin)->deleteJson('/api/v1/vehicles/'.$sold)->assertStatus(422);

        $this->inTenant($tenant['org'], function () use ($fresh, $sold) {
            $this->assertFalse(Vehicle::query()->whereKey($fresh)->exists());
            $this->assertTrue(Vehicle::query()->whereKey($sold)->exists());
        });
    }
    /** Without financials the toll rate is not shown, so it is not written either (audit 2026-11-27). */
    public function test_toll_rate_is_not_written_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $vehicle = $this->inTenant($tenant['org'], fn () => Vehicle::factory()->create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'toll_flat_rate_cents' => 1500,
        ]));
        $editor = $this->userWithRole($tenant, 'Fleet editor', [
            'fleet_management.view', 'fleet_management.vehicles.view', 'fleet_management.edit',
        ]);

        $this->signedInAs($editor)
            ->patchJson('/api/v1/vehicles/'.$vehicle->id, ['toll_flat_rate_cents' => 9900, 'toll_plan' => 'E-ZPass'])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $fresh = $vehicle->fresh();
            $this->assertSame(1500, $fresh->toll_flat_rate_cents);
            $this->assertSame('E-ZPass', $fresh->toll_plan, 'control: the rest of the save still lands');
        });
    }
}
