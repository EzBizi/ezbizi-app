<?php

namespace Tests\Feature\Monitoring;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /api/v1/monitoring/floor-plans and /{id} — the Monitoring "General" tab's
 * read side (2026-09-09-monitoring-general-map, Task 3).
 *
 * The load-bearing test is the first: a plan carries the `assigned_*` scope
 * trio, so `ScopeReach::constrain` decides which plans a caller may list, and
 * the one way to get it wrong is to assume the org branch fires for a company
 * manager (it does not — it is added only for an org-reaching actor). The
 * status test is the second load-bearing piece: a plan a caller may see can
 * still carry a node whose assignee they may not, and that node must resolve to
 * unassigned rather than leak the person's name.
 */
class FloorPlanReadTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /**
     * An org-level plan, a company-A plan, and a franchise-under-A plan seen
     * from three vantage points. The company/sibling-company expectations are
     * what ScopeReach actually yields, not what the plan sketched: a company
     * manager does NOT reach the organization, so the org-scoped plan is
     * invisible to them even though its `assigned_organization_id` is set —
     * ScopeReach's org branch is added only when the actor reaches the org.
     */
    public function test_reach_visibility(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $franchiseA = $alpha['franchise'];

        [$companyB] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $c = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true]);
            $f = Franchise::create([
                'organization_id' => $alpha['org']->id, 'company_id' => $c->id, 'name' => 'B F1', 'is_active' => true,
            ]);

            return [$c, $f];
        });

        $orgPlan = $this->makePlan($alpha, 'Org plan', []);
        $companyPlan = $this->makePlan($alpha, 'Company A plan', ['assigned_company_id' => $companyA->id]);
        $franchisePlan = $this->makePlan($alpha, 'Franchise A plan', ['assigned_franchise_id' => $franchiseA->id]);

        // An org viewer lists all three.
        $orgViewer = $this->userWithRole($alpha, 'Org Viewer', ['monitoring.view']);
        $data = collect(
            $this->signedInAs($orgViewer)->getJson('/api/v1/monitoring/floor-plans')->assertOk()->json('data')
        )->keyBy('id');

        $this->assertTrue($data->has($orgPlan->id));
        $this->assertTrue($data->has($companyPlan->id));
        $this->assertTrue($data->has($franchisePlan->id));

        // Scope label: the narrowest place the plan names, with its name.
        $this->assertSame(
            ['type' => 'organization', 'id' => $alpha['org']->id, 'label' => $alpha['org']->name],
            $data[$orgPlan->id]['scope'],
        );
        $this->assertSame(
            ['type' => 'company', 'id' => $companyA->id, 'label' => $companyA->name],
            $data[$companyPlan->id]['scope'],
        );
        $this->assertSame(
            ['type' => 'franchise', 'id' => $franchiseA->id, 'label' => $franchiseA->name],
            $data[$franchisePlan->id]['scope'],
        );

        // A company-A manager lists the company + franchise plans, but NOT the
        // org plan (they do not reach the org — observed, then encoded here).
        $companyAMgr = $this->companyManager($alpha, $companyA, 'Company A Mgr');
        $ids = $this->signedInAs($companyAMgr)->getJson('/api/v1/monitoring/floor-plans')->assertOk()->json('data.*.id');
        $this->assertContains($companyPlan->id, $ids);
        $this->assertContains($franchisePlan->id, $ids);
        $this->assertNotContains($orgPlan->id, $ids);

        // A sibling company-B manager lists none of A's plans.
        $companyBMgr = $this->companyManager($alpha, $companyB, 'Company B Mgr');
        $ids = $this->signedInAs($companyBMgr)->getJson('/api/v1/monitoring/floor-plans')->assertOk()->json('data.*.id');
        $this->assertNotContains($companyPlan->id, $ids);
        $this->assertNotContains($franchisePlan->id, $ids);
        $this->assertNotContains($orgPlan->id, $ids);
    }

    public function test_show_of_a_plan_outside_reach_404s(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $companyB = $this->inTenant($alpha['org'], fn () => Company::create([
            'organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true,
        ]));

        $companyPlan = $this->makePlan($alpha, 'Company A plan', ['assigned_company_id' => $alpha['company']->id]);

        $companyBMgr = $this->companyManager($alpha, $companyB, 'Company B Mgr');

        $this->signedInAs($companyBMgr)
            ->getJson('/api/v1/monitoring/floor-plans/'.$companyPlan->id)
            ->assertNotFound();
    }

    /**
     * A node assigned to a person with a recent device reads online with a name;
     * one assigned to a person the viewer cannot see reads unassigned with no
     * name (even though that person's own machine is online — the hiding is
     * about reach, not liveness); one with no assignee reads unassigned.
     */
    public function test_show_resolves_online_offline_and_hides_unseen_assignee(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $franchiseA = $alpha['franchise'];

        $franchiseB = $this->inTenant($alpha['org'], function () use ($alpha) {
            $c = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true]);

            return Franchise::create([
                'organization_id' => $alpha['org']->id, 'company_id' => $c->id, 'name' => 'B F1', 'is_active' => true,
            ]);
        });

        // Two people the company-A manager CAN see (assigned under A's franchise).
        $onlinePerson = $this->userWithRole($alpha, 'Online Person', [], onlyAt: $franchiseA);
        $offlinePerson = $this->userWithRole($alpha, 'Offline Person', [], onlyAt: $franchiseA);
        // One the manager CANNOT see (assigned under the sibling company).
        $unseenPerson = $this->userWithRole($alpha, 'Unseen Person', [], onlyAt: $franchiseB);

        $this->inTenant($alpha['org'], function () use ($alpha, $onlinePerson, $offlinePerson, $unseenPerson) {
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $onlinePerson->id,
                'last_seen_at' => now()->subMinutes(1),
            ]);
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $offlinePerson->id,
                'last_seen_at' => now()->subMinutes(10),
            ]);
            // The unseen person's machine is online — proving the node is hidden
            // by reach, not because they happen to be offline.
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $unseenPerson->id,
                'last_seen_at' => now()->subMinutes(1),
            ]);
        });

        $plan = $this->makePlan($alpha, 'Company A plan', ['assigned_company_id' => $companyA->id], [
            'grid' => ['cols' => 10, 'rows' => 10],
            'walls' => [],
            'nodes' => [
                ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $onlinePerson->id],
                ['id' => 'n2', 'x' => 2, 'y' => 1, 'label' => 'PC2', 'user_id' => $offlinePerson->id],
                ['id' => 'n3', 'x' => 3, 'y' => 1, 'label' => 'PC3', 'user_id' => $unseenPerson->id],
                ['id' => 'n4', 'x' => 4, 'y' => 1, 'label' => 'PC4', 'user_id' => null],
            ],
        ]);

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $status = $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertOk()
            ->json('data.status');

        $this->assertSame(['assigned' => true, 'online' => true, 'name' => $onlinePerson->name], $status['n1']);
        $this->assertSame(['assigned' => true, 'online' => false, 'name' => $offlinePerson->name], $status['n2']);
        $this->assertSame(['assigned' => false, 'online' => false, 'name' => null], $status['n3']);
        $this->assertSame(['assigned' => false, 'online' => false, 'name' => null], $status['n4']);
    }

    public function test_without_monitoring_view_index_and_show_are_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $plan = $this->makePlan($alpha, 'Org plan', []);

        $noAccess = $this->userWithRole($alpha, 'No Access', []);

        $this->signedInAs($noAccess)->getJson('/api/v1/monitoring/floor-plans')
            ->assertForbidden()->assertJsonStructure(['message']);

        $this->signedInAs($noAccess)->getJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    /**
     * @param  array{org: \App\Models\Organization, company: Company, franchise: Franchise}  $tenant
     * @param  array<string, mixed>  $scope  the `assigned_company_id` / `assigned_franchise_id` this plan names, if any
     * @param  array<string, mixed>  $layout
     */
    private function makePlan(array $tenant, string $name, array $scope, array $layout = []): MonitoringFloorPlan
    {
        return $this->inTenant($tenant['org'], fn () => MonitoringFloorPlan::create([
            'name' => $name,
            'layout' => $layout ?: ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => [], 'nodes' => []],
            ...$scope,
        ]));
    }

    /**
     * A user assigned at COMPANY level — the one shape BuildsTenants::userWithRole
     * does not build (it does org-level by default and franchise-level via
     * `onlyAt`). Same construction otherwise: a real role with the given grants.
     *
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  string[]  $perms
     */
    private function companyManager(array $tenant, Company $company, string $name, array $perms = ['monitoring.view']): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $company, $name, $perms) {
            $role = Role::create(['organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys($perms);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $name,
                'email' => strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $company->getKey(),
            ]);

            return $user;
        });
    }
}
