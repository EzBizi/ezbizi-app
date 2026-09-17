<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A tab is granted on its own (Jose, 2026-09-17).
 *
 * Reading what the business can take and CHANGING how much it can take are
 * different jobs. A dispatcher answering the phone needs Availability; nothing
 * about that role implies editing a franchise's capacity, and until today the
 * only grant available gave both.
 *
 * ── The positive control comes first, deliberately ───────────────
 *
 * The brief warns what a suite of 403s alone is worth: a role holding
 * `hr.hours.view` was refused by the PLATFORM layer — "Clock in is switched off
 * for this organization" — before any permission was consulted, and a file full
 * of "this is refused" assertions read that as the feature working. So every
 * refusal below is paired with the same request by somebody who holds the tab,
 * and that case is written first.
 */
class SchedulingTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function area(array $tenant, string $name = 'Dallas'): ServiceArea
    {
        app(TenantContext::class)->set($tenant['org']);

        return ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => $name,
        ]);
    }

    /** THE CONTROL. Availability answers for somebody who holds that tab. */
    public function test_availability_answers_for_a_role_holding_that_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $reader = $this->userWithRole($tenant, 'Dispatcher', [
            'scheduling.view',
            'scheduling.availability.view',
        ]);

        $this->signedInAs($reader)
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertOk();

        $this->signedInAs($reader)
            ->getJson('/api/v1/scheduling/suggest?q=Dal')
            ->assertOk();
    }

    /**
     * And the same person cannot reach Settings.
     *
     * This is the grant Jose asked for: the dispatcher reads the week and does
     * not decide how many jobs the franchise accepts.
     */
    public function test_availability_alone_cannot_read_or_write_capacity(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->area($tenant);

        $reader = $this->userWithRole($tenant, 'Dispatcher', [
            'scheduling.view',
            'scheduling.availability.view',
        ]);

        $this->signedInAs($reader)->getJson('/api/v1/scheduling/areas')->assertForbidden();

        $this->signedInAs($reader)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                ],
            ])
            ->assertForbidden();

        // The capacity-changing pattern endpoints sit behind the same grant,
        // even though no screen calls them today.
        $this->signedInAs($reader)
            ->postJson('/api/v1/scheduling/pattern/apply', [
                'area_ids' => [$area->id],
                'slots' => [],
            ])
            ->assertForbidden();
    }

    /** THE CONTROL, the other way round. Settings answers for its own tab. */
    public function test_settings_answers_for_a_role_holding_that_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->area($tenant);

        $editor = $this->userWithRole($tenant, 'Manager', [
            'scheduling.view',
            'scheduling.settings.view',
            'scheduling.edit',
        ]);

        $this->signedInAs($editor)->getJson('/api/v1/scheduling/areas')->assertOk();

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                ],
            ])
            ->assertOk();
    }

    /** And that person cannot read the Availability week. */
    public function test_settings_alone_cannot_read_availability(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $editor = $this->userWithRole($tenant, 'Manager', [
            'scheduling.view',
            'scheduling.settings.view',
        ]);

        $this->signedInAs($editor)
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertForbidden();

        $this->signedInAs($editor)
            ->getJson('/api/v1/scheduling/suggest?q=Dal')
            ->assertForbidden();
    }

    /**
     * The module grant alone opens nothing.
     *
     * `scheduling.view` puts the module in the rail. It is not a tab, and the
     * two are now separate questions.
     */
    public function test_the_module_grant_alone_reaches_no_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $nobody = $this->userWithRole($tenant, 'Viewer', ['scheduling.view']);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertForbidden();

        $this->signedInAs($nobody)->getJson('/api/v1/scheduling/areas')->assertForbidden();
    }
}
