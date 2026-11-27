<?php

namespace Tests\Feature\Api;

use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Service visits roll odometer/next-due; payments stay behind financials (spec §6.4, §6.5, §8).
 */
class VehicleServicePaymentsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_visit_with_higher_odometer_and_next_due_writes_through_to_vehicle(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'odometer_miles' => 10_000,
                'next_service_on' => now()->addMonth()->toDateString(),
                'next_service_miles' => 12_000,
            ]);
        });

        $nextOn = now()->addMonths(3)->toDateString();

        $response = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/service', [
                'kind' => 'oil',
                'occurred_on' => now()->toDateString(),
                'odometer_miles' => 10_500,
                'performed_by' => 'Quick Lube',
                'notes' => 'Synthetic',
                'next_service_on' => $nextOn,
                'next_service_miles' => 13_500,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('oil', $response['service']['kind']);
        $this->assertSame(10_500, $response['service']['odometer_miles']);
        $this->assertSame($nextOn, $response['service']['next_service_on']);
        $this->assertSame(13_500, $response['service']['next_service_miles']);

        $this->inTenant($tenant['org'], function () use ($vehicle, $nextOn) {
            $fresh = $vehicle->fresh();
            $this->assertSame(10_500, $fresh->odometer_miles);
            $this->assertSame($nextOn, $fresh->next_service_on?->toDateString());
            $this->assertSame(13_500, $fresh->next_service_miles);
            $this->assertSame(1, $fresh->serviceVisits()->count());
        });
    }

    public function test_payment_get_as_view_only_role_is_forbidden(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $viewer = $this->userWithRole($tenant, 'Viewer', [
            'fleet_management.view',
        ]);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->signedInAs($viewer)
            ->getJson('/api/v1/vehicles/'.$vehicle->id.'/payments')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Amounts on the fleet are hidden for your role.',
            ]);
    }

    public function test_payment_post_without_financials_is_forbidden(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $editor = $this->userWithRole($tenant, 'Editor', [
            'fleet_management.view',
            'fleet_management.edit',
        ]);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->signedInAs($editor)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/payments', [
                'category' => 'maintenance',
                'amount_cents' => 12_500,
                'occurred_on' => now()->toDateString(),
                'method' => 'cash',
            ])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Amounts on the fleet are hidden for your role.',
            ]);
    }

    public function test_payment_linked_policy_from_another_vehicle_is_rejected(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vehicle, $otherPolicyId] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            $other = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            $policy = VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $other->id,
                'carrier' => 'Other Mutual',
                'ended_on' => null,
            ]);

            return [$vehicle, $policy->id];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/payments', [
                'category' => 'insurance',
                'amount_cents' => 90_000,
                'occurred_on' => now()->toDateString(),
                'method' => 'ach',
                'insurance_policy_id' => $otherPolicyId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['insurance_policy_id']);
    }
    /** An older visit logged late keeps the newer visit's next service (audit 2026-11-27). */
    public function test_an_older_visit_does_not_overwrite_the_next_service(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $vehicle = $this->inTenant($tenant['org'], fn () => Vehicle::factory()->create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
        ]));
        $log = fn (string $on, string $next, int $miles) => $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/service', [
                'kind' => 'oil', 'occurred_on' => $on,
                'next_service_on' => $next, 'next_service_miles' => $miles,
            ])->assertOk();

        $log(now()->toDateString(), now()->addMonths(3)->toDateString(), 15_000);
        $log(now()->subMonths(2)->toDateString(), now()->addMonth()->toDateString(), 12_000);

        $this->inTenant($tenant['org'], function () use ($vehicle) {
            $fresh = $vehicle->fresh();
            $this->assertSame(now()->addMonths(3)->toDateString(), $fresh->next_service_on?->toDateString());
            $this->assertSame(15_000, $fresh->next_service_miles);
        });
    }
}
