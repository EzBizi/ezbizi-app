<?php

namespace Tests\Feature\Fleet;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fleet actions need their own permission keys — view alone cannot hand keys.
 */
class VehiclePolicyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_view_alone_cannot_assign_but_assign_permission_can(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);
        $assigner = $this->userWithRole($tenant, 'Assigner', [
            'fleet_management.view',
            'fleet_management.assign',
        ]);

        $this->inTenant($tenant['org'], function () use ($viewer, $assigner, $vehicle) {
            $denied = Gate::forUser($viewer->fresh())->inspect('assign', $vehicle);
            $this->assertTrue($denied->denied());
            $this->assertStringContainsString('role', (string) $denied->message());

            $allowed = Gate::forUser($assigner->fresh())->inspect('assign', $vehicle);
            $this->assertTrue($allowed->allowed());
        });
    }

    public function test_payment_view_needs_financials_with_the_fleet_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $payment = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return \App\Models\VehiclePayment::factory()->create([
                'vehicle_id' => $vehicle->id,
            ]);
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'fleet_management.view',
            'fleet_management.financials',
        ]);

        $this->inTenant($tenant['org'], function () use ($viewer, $money, $payment) {
            $denied = Gate::forUser($viewer->fresh())->inspect('view', $payment);
            $this->assertTrue($denied->denied());
            $this->assertSame(
                'Amounts on the fleet are hidden for your role.',
                $denied->message(),
            );

            $allowed = Gate::forUser($money->fresh())->inspect('view', $payment);
            $this->assertTrue($allowed->allowed());
        });
    }
}
