<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoringFloorPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `MonitoringFloorPlan` — the table + model behind the Monitoring "General" tab
 * (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
 *
 * Two invariants the rest of the feature rests on: `layout` survives the round
 * trip as an array (the controller reads and writes it as a document), and
 * `assigned_organization_id` is stamped from the tenant so ScopeReach's org
 * branch has something to match — nobody has to remember to pass it.
 */
class FloorPlanModelTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_layout_round_trips_as_an_array(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $plan = $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::create([
            'name'    => 'Head office',
            'layout'  => [
                'grid'  => ['cols' => 40, 'rows' => 28],
                'walls' => [['x1' => 2, 'y1' => 2, 'x2' => 12, 'y2' => 2]],
                'nodes' => [['id' => 'n1', 'x' => 4, 'y' => 6, 'label' => 'Desk', 'user_id' => null]],
            ],
        ]));

        // Reload from the database so the array cast is exercised on the way back
        // in, not just the value we happened to set on the way out.
        $fresh = $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::findOrFail($plan->id));

        $this->assertIsArray($fresh->layout);
        $this->assertSame(4, $fresh->layout['nodes'][0]['x']);
        $this->assertSame(40, $fresh->layout['grid']['cols']);
    }

    public function test_organization_id_is_set_by_the_tenant(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $plan = $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::create([
            'name'   => 'Head office',
            'layout' => ['grid' => ['cols' => 40, 'rows' => 28], 'walls' => [], 'nodes' => []],
        ]));

        // Created without naming an organization, yet the scope column ScopeReach
        // reads is the tenant org — stamped, never passed.
        $this->assertSame($alpha['org']->id, $plan->assigned_organization_id);
    }
}
