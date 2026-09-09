<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
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

    /**
     * Deleting a company takes its plans with it - it does not WIDEN them.
     *
     * `assigned_company_id` cascades rather than nulling on delete, and that
     * distinction is a visibility one, not a tidiness one: a plan whose company
     * column went null would still carry `assigned_organization_id` (always
     * stamped), which is exactly the shape of an ORGANIZATION-scoped plan - so
     * a company deletion would quietly publish that company's floor plans to
     * everyone in the org. `invitations` cascades the same trio for the same
     * reason.
     *
     * The org-level plan is the control: without it, a cascade that deleted
     * every plan in the tenant would read exactly like a correct one.
     *
     * `forceDelete`, because Company soft-deletes: an ordinary delete leaves
     * the row (and the foreign key) in place, so it exercises no referential
     * rule at all. What is pinned here is what happens when the company row
     * actually goes - which is the only moment either rule could fire.
     */
    public function test_deleting_a_company_deletes_its_plans_and_leaves_the_org_plan(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];

        [$companyPlan, $orgPlan] = $this->inTenant($alpha['org'], fn () => [
            MonitoringFloorPlan::create([
                'name' => 'Company floor',
                'layout' => ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => [], 'nodes' => []],
                'assigned_company_id' => $companyA->getKey(),
            ]),
            MonitoringFloorPlan::create([
                'name' => 'Org floor',
                'layout' => ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => [], 'nodes' => []],
            ]),
        ]);

        $this->inTenant($alpha['org'], fn () => Company::findOrFail($companyA->getKey())->forceDelete());

        $this->inTenant($alpha['org'], function () use ($companyPlan, $orgPlan) {
            $this->assertNull(
                MonitoringFloorPlan::find($companyPlan->id),
                'A deleted company must take its plans with it, not widen them to the organization.'
            );

            $survivor = MonitoringFloorPlan::find($orgPlan->id);
            $this->assertNotNull($survivor);
            $this->assertNull($survivor->assigned_company_id);
        });
    }
}
