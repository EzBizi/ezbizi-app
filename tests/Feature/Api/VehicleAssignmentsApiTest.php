<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Handing and taking back vehicle keys (spec §6.3, §12).
 */
class VehicleAssignmentsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_viewer_without_assign_cannot_hand_keys(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);
        $driver = $this->userWithRole($tenant, 'Driver', ['fleet_management.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/assignments', [
                'user_id' => $driver->id,
            ])
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'You can view this vehicle, but you cannot hand over the keys.',
            ]);
    }

    public function test_assign_sibling_company_user_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vehicle, $outsider] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            $sibling = Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Sibling Co',
                'is_active' => true,
            ]);

            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'SiblingWorker',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['fleet_management.view']);

            $outsider = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => 'Sibling Worker',
                'email' => 'sibling@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $outsider->id,
                'assigned_company_id' => $sibling->id,
            ]);

            return [$vehicle, $outsider];
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/assignments', [
                'user_id' => $outsider->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You can assign people who work in this company. This person does not.',
            ]);
    }

    public function test_reassign_releases_previous_open_assignment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $first = $this->userWithRole($tenant, 'FirstDriver', ['fleet_management.view']);
        $second = $this->userWithRole($tenant, 'SecondDriver', ['fleet_management.view']);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant, $first) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            VehicleAssignment::factory()->create([
                'vehicle_id' => $vehicle->id,
                'user_id' => $first->id,
                'assigned_at' => now()->subDay(),
                'released_at' => null,
                'note' => 'first keys',
            ]);

            return $vehicle;
        });

        $payload = $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/assignments', [
                'user_id' => $second->id,
                'note' => 'handed to second',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame($second->id, $payload['assignment']['user']['id']);
        $this->assertNull($payload['assignment']['released_at']);
        $this->assertSame('handed to second', $payload['assignment']['note']);

        $this->inTenant($tenant['org'], function () use ($vehicle, $first, $second) {
            $rows = VehicleAssignment::query()
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('assigned_at')
                ->get();

            $this->assertCount(2, $rows);
            $this->assertSame($first->id, $rows[0]->user_id);
            $this->assertNotNull($rows[0]->released_at);
            $this->assertSame($second->id, $rows[1]->user_id);
            $this->assertNull($rows[1]->released_at);
            $this->assertNull($vehicle->fresh()->openAssignment()?->released_at);
            $this->assertSame($second->id, $vehicle->fresh()->openAssignment()?->user_id);
        });
    }

    public function test_unassign_with_nobody_holding_keys_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/unassign')
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Nobody has the keys.',
            ]);
    }
}
