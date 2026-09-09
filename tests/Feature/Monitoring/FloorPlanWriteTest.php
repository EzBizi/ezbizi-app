<?php

namespace Tests\Feature\Monitoring;

use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoringFloorPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * POST/PUT/DELETE /api/v1/monitoring/floor-plans — the Monitoring "General"
 * tab's write side (2026-09-09-monitoring-general-map, Task 4).
 *
 * The load-bearing pieces are scope validation and assignee validation. A plan
 * is scoped by the `assigned_*` trio, so `ScopeReach::assertCovers` decides
 * whether the actor may create at the level they chose — a scope above their
 * level is a 422, not a 403. And every node `user_id` must be a person
 * `constrainToVisiblePeople` returns, so a tile cannot be assigned to somebody
 * the actor may not see (which would leak them at read time). Each write is an
 * audited fact (§2.2): create/update/delete leave exactly one `monitoring.map.*`
 * row, asserted non-vacuously by action + target.
 */
class FloorPlanWriteTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function layout(array $nodes = [], array $walls = []): array
    {
        return ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => $walls, 'nodes' => $nodes];
    }

    /**
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $layout
     */
    private function makePlan(array $tenant, string $name, array $scope, array $layout = []): MonitoringFloorPlan
    {
        return $this->inTenant($tenant['org'], fn () => MonitoringFloorPlan::create([
            'name' => $name,
            'layout' => $layout ?: $this->layout(),
            ...$scope,
        ]));
    }

    /**
     * A user assigned at COMPANY level with the given grants — the shape
     * BuildsTenants does not build (org-level by default, franchise via onlyAt).
     *
     * @param  array{org: \App\Models\Organization}  $tenant
     * @param  string[]  $perms
     */
    private function companyManager(array $tenant, Company $company, string $name, array $perms = ['monitoring.map.manage']): User
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

    // ── create + scope validation ────────────────────────────────────

    public function test_create_at_a_covered_company_scope_succeeds_and_is_scoped_right(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $row = $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Ground Floor',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
            'layout' => $this->layout(),
        ])->assertCreated()->json('data');

        $this->assertSame('Ground Floor', $row['name']);
        $this->assertSame(['type' => 'company', 'id' => $companyA->id, 'label' => $companyA->name], $row['scope']);

        $plan = $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::query()->sole());
        $this->assertSame($companyA->id, $plan->assigned_company_id);
        $this->assertNull($plan->assigned_franchise_id);
        $this->assertSame($alpha['org']->id, $plan->assigned_organization_id);
        $this->assertSame($manager->getKey(), $plan->created_by);
    }

    public function test_create_at_a_scope_above_the_actor_is_a_422(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $franchiseA = $alpha['franchise'];

        // A franchise-level manager: reaches their franchise, not the company
        // above it nor the organization.
        $franchiseMgr = $this->userWithRole($alpha, 'Franchise Mgr', ['monitoring.map.manage'], onlyAt: $franchiseA);

        // Naming the COMPANY above them → 422 on scope.
        $this->signedInAs($franchiseMgr)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Too high',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['scope']);

        // Naming the ORGANIZATION → 422 on scope.
        $this->signedInAs($franchiseMgr)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Higher still',
            'scope' => ['type' => 'organization', 'id' => $alpha['org']->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['scope']);

        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::query()->count()));
    }

    // ── update / destroy outside reach → 404 ─────────────────────────

    public function test_update_and_destroy_of_a_plan_outside_reach_404(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];

        $companyB = $this->inTenant($alpha['org'], fn () => Company::create([
            'organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true,
        ]));

        $plan = $this->makePlan($alpha, 'A plan', ['assigned_company_id' => $companyA->id]);

        $companyBMgr = $this->companyManager($alpha, $companyB, 'Company B Mgr');

        $this->signedInAs($companyBMgr)
            ->putJson('/api/v1/monitoring/floor-plans/'.$plan->id, ['name' => 'Hijack'])
            ->assertNotFound();

        $this->signedInAs($companyBMgr)
            ->deleteJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertNotFound();

        $this->assertSame('A plan', $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::find($plan->id))->name);
    }

    // ── node assignee visibility ─────────────────────────────────────

    public function test_a_node_naming_an_unseeable_person_is_refused_and_a_visible_one_is_accepted(): void
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

        // Visible to the company-A manager (under A's franchise); unseeable (under B).
        $visible = $this->userWithRole($alpha, 'Visible Person', [], onlyAt: $franchiseA);
        $unseen = $this->userWithRole($alpha, 'Unseen Person', [], onlyAt: $franchiseB);

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        // A create naming the unseeable person → 422 on layout.
        $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Leaky',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
            'layout' => $this->layout([
                ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $unseen->id],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors(['layout']);

        // A create naming only the visible person → 201.
        $id = $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'OK',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
            'layout' => $this->layout([
                ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $visible->id],
            ]),
        ])->assertCreated()->json('data.id');

        // A PUT that then tries to reassign that tile to the unseeable person → 422.
        $this->signedInAs($manager)->putJson('/api/v1/monitoring/floor-plans/'.$id, [
            'layout' => $this->layout([
                ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $unseen->id],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors(['layout']);
    }

    // ── malformed layout ─────────────────────────────────────────────

    public function test_malformed_layouts_are_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $bad = [
            // missing grid
            ['walls' => [], 'nodes' => []],
            // a wall with 3 ints
            ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => [['x1' => 0, 'y1' => 0, 'x2' => 1]], 'nodes' => []],
            // a node missing its id
            ['grid' => ['cols' => 10, 'rows' => 10], 'walls' => [], 'nodes' => [['x' => 1, 'y' => 1, 'label' => 'PC']]],
        ];

        foreach ($bad as $layout) {
            $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
                'name' => 'Bad',
                'scope' => ['type' => 'company', 'id' => $companyA->id],
                'layout' => $layout,
            ])->assertStatus(422)->assertJsonValidationErrors(['layout']);
        }

        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::query()->count()));
    }

    // ── §2.2 audit trail ─────────────────────────────────────────────

    public function test_create_update_delete_each_write_one_map_audit_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $id = $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Floor 1',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
        ])->assertCreated()->json('data.id');

        $this->signedInAs($manager)
            ->putJson('/api/v1/monitoring/floor-plans/'.$id, ['name' => 'Floor 2'])
            ->assertOk();

        $this->signedInAs($manager)
            ->deleteJson('/api/v1/monitoring/floor-plans/'.$id)
            ->assertNoContent();

        $rows = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $alpha['org']->id)
            ->where('action', 'like', 'monitoring.map.%')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['monitoring.map.created', 'monitoring.map.updated', 'monitoring.map.deleted'],
            $rows->pluck('action')->all(),
            'each write action leaves exactly one audit row',
        );

        // Non-vacuous: the created row names the plan and the actor, so removing
        // the log() call (or logging the wrong target) fails here.
        $created = $rows->firstWhere('action', 'monitoring.map.created');
        $this->assertSame($id, $created->target_id);
        $this->assertSame($manager->getKey(), $created->actor_id);
    }

    // ── gating ───────────────────────────────────────────────────────

    public function test_without_map_manage_all_three_writes_are_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $plan = $this->makePlan($alpha, 'A plan', ['assigned_company_id' => $companyA->id]);

        // A view-only user: the read still works (Task 3), but writes are refused.
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/floor-plans/'.$plan->id)->assertOk();

        $this->signedInAs($viewer)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Nope',
            'scope' => ['type' => 'company', 'id' => $companyA->id],
        ])->assertForbidden()->assertJsonStructure(['message']);

        $this->signedInAs($viewer)->putJson('/api/v1/monitoring/floor-plans/'.$plan->id, ['name' => 'Nope'])
            ->assertForbidden()->assertJsonStructure(['message']);

        $this->signedInAs($viewer)->deleteJson('/api/v1/monitoring/floor-plans/'.$plan->id)
            ->assertForbidden()->assertJsonStructure(['message']);
    }
}
