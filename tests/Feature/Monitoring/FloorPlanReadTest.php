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
        $orgViewer = $this->userWithRole($alpha, 'Org Viewer', ['monitoring.view', 'monitoring.general.view']);
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

        $onlineBeat = now()->subMinutes(1);
        $offlineBeat = now()->subMinutes(10);

        $this->inTenant($alpha['org'], function () use ($alpha, $onlinePerson, $offlinePerson, $unseenPerson, $onlineBeat, $offlineBeat) {
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $onlinePerson->id,
                'last_seen_at' => $onlineBeat,
            ]);
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $offlinePerson->id,
                'last_seen_at' => $offlineBeat,
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

        // This caller holds `monitoring.view` alone, so no `specs` key is sent
        // at all — see FloorPlansController::status. The seen-at stamp is not
        // gated and rides along on every resolved node.
        $this->assertSame([
            'assigned' => true, 'online' => true, 'name' => $onlinePerson->name,
            'last_seen_at' => $onlineBeat->toIso8601String(),
        ], $status['n1']);
        $this->assertSame([
            'assigned' => true, 'online' => false, 'name' => $offlinePerson->name,
            'last_seen_at' => $offlineBeat->toIso8601String(),
        ], $status['n2']);
        $this->assertSame(
            ['assigned' => false, 'online' => false, 'name' => null, 'last_seen_at' => null],
            $status['n3'],
        );
        $this->assertSame(
            ['assigned' => false, 'online' => false, 'name' => null, 'last_seen_at' => null],
            $status['n4'],
        );
    }

    /**
     * The sidebar's two extra facts (Task 8): a visible assignee's node carries
     * their most recent `last_seen_at` and the specs of their most recently
     * seen machine that HAS any — and an unseeable assignee's node carries
     * neither, though hers is the machine with both.
     *
     * The online person deliberately owns TWO devices: her newest beat is from
     * a device that has reported no specs at all, and the specs come from an
     * older one. So the two fields cannot both be read off "the newest row" —
     * a test where one device answered both would pass against an
     * implementation that only ever looks at the first row it sees.
     */
    public function test_show_carries_last_seen_at_and_specs_for_a_visible_assignee(): void
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

        $seenPerson = $this->userWithRole($alpha, 'Seen Person', [], onlyAt: $franchiseA);
        $unseenPerson = $this->userWithRole($alpha, 'Unseen Person', [], onlyAt: $franchiseB);

        $newestBeat = now()->subMinutes(2);
        $olderBeat = now()->subMinutes(90);
        $specs = ['ram_gb' => 32, 'disk_free_gb' => 128, 'os_version' => 'Windows 11'];

        $this->inTenant($alpha['org'], function () use ($alpha, $seenPerson, $unseenPerson, $newestBeat, $olderBeat, $specs) {
            // Newest beat, no specs: a browser session reports none.
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $seenPerson->id,
                'last_seen_at' => $newestBeat, 'specs' => null,
            ]);
            // Older beat, and the only machine that has ever reported specs.
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $seenPerson->id,
                'last_seen_at' => $olderBeat, 'specs' => $specs,
            ]);
            // The unseeable person's machine is online AND has specs — so the
            // nulls on her node are reach hiding them, not an absence of data.
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $unseenPerson->id,
                'last_seen_at' => now()->subMinute(), 'specs' => ['ram_gb' => 8],
            ]);
        });

        $plan = $this->makePlan($alpha, 'Company A plan', ['assigned_company_id' => $companyA->id], [
            'grid' => ['cols' => 10, 'rows' => 10],
            'walls' => [],
            'nodes' => [
                ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $seenPerson->id],
                ['id' => 'n2', 'x' => 2, 'y' => 1, 'label' => 'PC2', 'user_id' => $unseenPerson->id],
                ['id' => 'n3', 'x' => 3, 'y' => 1, 'label' => 'PC3', 'user_id' => null],
            ],
        ]);

        $manager = $this->companyManager(
            $alpha, $companyA, 'Company A Mgr', ['monitoring.view', 'monitoring.general.view', 'monitoring.specs.view']
        );

        $status = $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertOk()
            ->json('data.status');

        // Field by field, and the key list too, so nothing extra rides along.
        // `specs` is compared with assertEquals rather than assertSame: MySQL's
        // JSON type reorders an object's keys on write, so the array comes back
        // with the same pairs in a different order.
        $node = $status['n1'];
        $this->assertSame(['assigned', 'online', 'name', 'last_seen_at', 'specs'], array_keys($node));
        $this->assertTrue($node['assigned']);
        $this->assertTrue($node['online']);
        $this->assertSame($seenPerson->name, $node['name']);
        $this->assertSame($newestBeat->toIso8601String(), $node['last_seen_at']);
        $this->assertEquals($specs, $node['specs']);

        // The control: everything the visible node carried, absent here.
        $this->assertSame(
            ['assigned' => false, 'online' => false, 'name' => null, 'last_seen_at' => null, 'specs' => null],
            $status['n2'],
        );
        $this->assertSame(
            ['assigned' => false, 'online' => false, 'name' => null, 'last_seen_at' => null, 'specs' => null],
            $status['n3'],
        );

        // Without `monitoring.specs.view` the specs KEY is absent, not null —
        // the same withholding DevicesController::present does. `last_seen_at`
        // is still there, which is what proves the key is gone because of the
        // grant and not because this caller resolved nothing.
        $viewerOnly = $this->companyManager($alpha, $companyA, 'Company A Viewer', ['monitoring.view', 'monitoring.general.view']);

        $plain = $this->signedInAs($viewerOnly)
            ->getJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertOk()
            ->json('data.status');

        $this->assertArrayNotHasKey('specs', $plain['n1']);
        $this->assertSame($newestBeat->toIso8601String(), $plain['n1']['last_seen_at']);
        $this->assertArrayNotHasKey('specs', $plain['n2']);
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
    private function companyManager(array $tenant, Company $company, string $name, array $perms = ['monitoring.view', 'monitoring.general.view']): User
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
