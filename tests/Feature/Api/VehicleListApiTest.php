<?php

namespace Tests\Feature\Api;

use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fleet list: pool visibility for franchise viewers, default status set, due_soon.
 */
class VehicleListApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_franchise_viewer_sees_pool_and_own_tag_and_default_hides_sold(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$franchiseA1, $ids] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $franchiseA1 = $tenant['franchise'];

            $franchiseA2 = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'name' => 'Alpha F2',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            $pool = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => null,
                'plate' => 'POOL-A',
                'status' => 'active',
            ]);

            $own = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $franchiseA1->id,
                'plate' => 'TAG-A1',
                'status' => 'active',
            ]);

            $sibling = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $franchiseA2->id,
                'plate' => 'TAG-A2',
                'status' => 'active',
            ]);

            $sold = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $franchiseA1->id,
                'plate' => 'SOLD-A1',
                'status' => 'sold',
            ]);

            return [$franchiseA1, [
                'pool' => $pool->id,
                'own' => $own->id,
                'sibling' => $sibling->id,
                'sold' => $sold->id,
            ]];
        });

        $viewer = $this->userWithRole(
            $tenant,
            'FleetViewer',
            ['fleet_management.view'],
            onlyAt: $franchiseA1,
        );

        $this->inTenant($tenant['org'], function () use ($viewer, $franchiseA1) {
            User::find($viewer->id)->forceFill([
                'preferences' => array_merge($viewer->preferences ?? [], [
                    'navigation.scope' => 'franchise:'.$franchiseA1->id,
                ]),
            ])->save();
        });

        $listed = $this->signedInAs($viewer)
            ->getJson('/api/v1/vehicles')
            ->assertOk()
            ->json('data');

        $listedIds = collect($listed)->pluck('id')->all();

        $this->assertContains($ids['pool'], $listedIds);
        $this->assertContains($ids['own'], $listedIds);
        $this->assertNotContains($ids['sibling'], $listedIds);
        $this->assertNotContains($ids['sold'], $listedIds);

        $this->assertArrayHasKey('meta', $this->signedInAs($viewer)
            ->getJson('/api/v1/vehicles')
            ->assertOk()
            ->json());
    }

    public function test_due_soon_includes_vehicle_with_service_date_yesterday(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $dueId = $this->inTenant($tenant['org'], function () use ($tenant) {
            $due = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'plate' => 'DUE-YDAY',
                'status' => 'active',
                'next_service_on' => now()->subDay()->toDateString(),
            ]);

            Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'plate' => 'NOT-DUE',
                'status' => 'active',
                'next_service_on' => now()->addDays(60)->toDateString(),
            ]);

            return $due->id;
        });

        $listed = $this->signedInAs($admin)
            ->getJson('/api/v1/vehicles?due_soon=1')
            ->assertOk()
            ->json('data');

        $listedIds = collect($listed)->pluck('id')->all();

        $this->assertContains($dueId, $listedIds);
        $this->assertCount(1, $listedIds);
    }
}
