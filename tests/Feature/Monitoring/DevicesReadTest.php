<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /api/v1/monitoring/devices — the roster (2026-08-28-monitoring-pc-specs,
 * Task 5).
 *
 * The load-bearing test is the last one: reach here is scoped by the DEVICE'S
 * OWNER (DevicesController::visibleTo, copied from HrPeopleController), not by
 * a place column on the device itself, and the one way to get that wrong is
 * to iterate `$user->companies` — which hands back every company a person sits
 * in, siblings included, rather than what the CALLER'S reach actually covers.
 */
class DevicesReadTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_no_monitoring_view_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/devices')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_specs_are_withheld_without_specs_view_and_returned_with_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'capabilities' => ['activity', 'specs'], 'specs' => ['ram_gb' => 16],
        ]));

        $seer = $this->userWithRole($alpha, 'Seer', ['monitoring.view']);
        $resp = $this->signedInAs($seer)->getJson('/api/v1/monitoring/devices')
            ->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('specs', $resp);

        $full = $this->userWithRole($alpha, 'Full', ['monitoring.view', 'monitoring.specs.view']);
        $resp2 = $this->signedInAs($full)->getJson('/api/v1/monitoring/devices')
            ->assertOk()->json('data.0');
        $this->assertSame(16, $resp2['specs']['ram_gb']);
    }

    /**
     * §3: two companies under one org; a viewer scoped to company A's
     * franchise must not see a device owned by someone assigned under company
     * B. Setup mirrors HrPeopleTest::test_the_list_is_filtered_to_the_callers_reach —
     * a second company + franchise built inline, and a person placed there
     * with `userWithRole(..., onlyAt: $elsewhere)`.
     */
    public function test_a_company_view_does_not_list_a_sibling_companys_devices(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });

        // Somebody assigned under the SIBLING company, with a device.
        $theirs = $this->userWithRole($alpha, 'Distant', [], onlyAt: $elsewhere);
        $theirDevice = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $theirs->getKey(),
        ]));

        // A viewer scoped to company A's own franchise only.
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['monitoring.view'], onlyAt: $alpha['franchise'],
        );
        $mineDevice = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $manager->getKey(),
        ]));

        $ids = $this->signedInAs($manager)->getJson('/api/v1/monitoring/devices')
            ->assertOk()->json('data.*.id');

        $this->assertContains($mineDevice->id, $ids);
        $this->assertNotContains($theirDevice->id, $ids);
    }
}
