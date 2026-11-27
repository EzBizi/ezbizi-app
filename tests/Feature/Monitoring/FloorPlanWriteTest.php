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
    private function companyManager(array $tenant, Company $company, string $name, array $perms = ['monitoring.map.view', 'monitoring.map.manage']): User
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
        $franchiseMgr = $this->userWithRole($alpha, 'Franchise Mgr', ['monitoring.map.view', 'monitoring.map.manage'], onlyAt: $franchiseA);

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

    // ── the create panel's scope list ────────────────────────────────

    /**
     * GET /monitoring/floor-plans/scope-options answers with exactly the levels
     * `store`'s assertCovers will accept - which is why it exists.
     *
     * The panel used to build the list itself from `me.organization`, which is
     * "the org I am in", not reach: every actor got an Organization row, and a
     * company manager who took the preselected one hit a guaranteed 422. This
     * endpoint is `ScopeReach::optionsFor`, the object that NULLS organization
     * for exactly that reason.
     *
     * The company row is the positive control: without it, an endpoint that
     * returned nothing at all would read exactly like a working filter.
     */
    public function test_scope_options_offer_the_organization_only_to_an_org_level_actor(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];

        $orgManager = $this->userWithRole($alpha, 'Org Mgr', ['monitoring.map.view', 'monitoring.map.manage']);

        $orgRows = $this->signedInAs($orgManager)
            ->getJson('/api/v1/monitoring/floor-plans/scope-options')
            ->assertOk()->json('data');

        $this->assertContains('organization', array_column($orgRows, 'type'));
        $this->assertSame($alpha['org']->id, collect($orgRows)->firstWhere('type', 'organization')['id']);

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $rows = $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/floor-plans/scope-options')
            ->assertOk()->json('data');

        $this->assertNotContains('organization', array_column($rows, 'type'));

        // Positive control: their own company IS offered, with its plain name.
        $company = collect($rows)->firstWhere('id', $companyA->id);
        $this->assertNotNull($company, 'A company manager must still be offered their own company.');
        $this->assertSame('company', $company['type']);
        $this->assertSame($companyA->name, $company['label']);

        // And the list agrees with the write: the offered company scope saves.
        $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'From the offered scope',
            'scope' => ['type' => $company['type'], 'id' => $company['id']],
        ])->assertCreated();
    }

    public function test_scope_options_without_map_manage_are_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view', 'monitoring.map.view']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/floor-plans/scope-options')
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    // ── update / destroy outside reach → 404 ─────────────────────────

    public function test_a_rename_without_a_layout_keeps_the_saved_layout(): void
    {
        // Audit 11-27: the screen now renames with the name alone; the stored
        // walls and tiles must survive it.
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->companyManager($alpha, $alpha['company'], 'Mgr');
        $id = $this->signedInAs($manager)->postJson('/api/v1/monitoring/floor-plans', [
            'name' => 'Before',
            'scope' => ['type' => 'company', 'id' => $alpha['company']->id],
            'layout' => $this->layout([['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1']]),
        ])->assertCreated()->json('data.id');

        $this->signedInAs($manager)->putJson('/api/v1/monitoring/floor-plans/'.$id, ['name' => 'After'])->assertOk();

        $plan = $this->signedInAs($manager)->getJson('/api/v1/monitoring/floor-plans/'.$id)->assertOk()->json('data');
        $this->assertSame('After', $plan['name']);
        $this->assertSame('n1', $plan['layout']['nodes'][0]['id']);
    }

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

    /**
     * The narrower editor's plan is SAVABLE even though one of its tiles names
     * somebody they cannot see - and the person stays put.
     *
     * `show` hands the layout back verbatim, unseeable assignee id included,
     * and the editor PUTs that document back with whatever it changed. Before
     * the fix, validating every non-null user_id from scratch made such a plan
     * permanently unsavable for the narrower editor: moving an unrelated tile,
     * or renaming the plan, 422d on a node they can neither see nor identify.
     *
     * The second assertion is the one that stops the "fix" from being a
     * silent unassignment: the stored user_id for that node must be EXACTLY
     * what it was, not null. Dropping the person would also pass a 200 check.
     */
    public function test_an_unrelated_edit_saves_when_a_tile_names_a_person_the_editor_cannot_see(): void
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

        $visible = $this->userWithRole($alpha, 'Visible Person', [], onlyAt: $franchiseA);

        // Placed on the plan by somebody with wider reach; out of the company-A
        // manager's reach entirely.
        $unseen = $this->userWithRole($alpha, 'Unseen Person', [], onlyAt: $franchiseB);

        $plan = $this->makePlan($alpha, 'Ground Floor', ['assigned_company_id' => $companyA->id], $this->layout([
            ['id' => 'n1', 'x' => 1, 'y' => 1, 'label' => 'PC1', 'user_id' => $visible->id],
            ['id' => 'n3', 'x' => 3, 'y' => 3, 'label' => 'PC3', 'user_id' => $unseen->id],
        ]));

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        // n1 is dragged; n3 is echoed back exactly as it was read.
        $this->signedInAs($manager)->putJson('/api/v1/monitoring/floor-plans/'.$plan->id, [
            'layout' => $this->layout([
                ['id' => 'n1', 'x' => 5, 'y' => 5, 'label' => 'PC1', 'user_id' => $visible->id],
                ['id' => 'n3', 'x' => 3, 'y' => 3, 'label' => 'PC3', 'user_id' => $unseen->id],
            ]),
        ])->assertOk();

        // A rename of the same plan, which re-sends the layout, is fine too.
        $this->signedInAs($manager)->putJson('/api/v1/monitoring/floor-plans/'.$plan->id, [
            'name' => 'First Floor',
            'layout' => $this->layout([
                ['id' => 'n1', 'x' => 5, 'y' => 5, 'label' => 'PC1', 'user_id' => $visible->id],
                ['id' => 'n3', 'x' => 3, 'y' => 3, 'label' => 'PC3', 'user_id' => $unseen->id],
            ]),
        ])->assertOk();

        $fresh = $this->inTenant($alpha['org'], fn () => MonitoringFloorPlan::find($plan->id));

        $nodes = collect($fresh->layout['nodes'])->keyBy('id');
        $this->assertSame(5, $nodes['n1']['x']);
        // The load-bearing one: the out-of-reach person was NOT quietly dropped.
        $this->assertSame($unseen->getKey(), $nodes['n3']['user_id']);
        $this->assertSame('First Floor', $fresh->name);
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
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view', 'monitoring.map.view']);

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

    public function test_renaming_one_desk_leaves_the_rest_of_the_layout_alone(): void
    {
        // Jose, 2026-09-23: the desk name is editable from the ownership
        // chart, which holds no geometry — so this path exists precisely to
        // avoid a read-modify-write of the whole plan, and the assertion that
        // matters is that everything it did NOT touch survived.
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];

        $plan = $this->makePlan($alpha, 'Main Office', [
            'assigned_organization_id' => $alpha['org']->id,
            'assigned_company_id' => $companyA->getKey(),
        ], [
            'grid' => ['cols' => 20, 'rows' => 14],
            'walls' => [['x1' => 1, 'y1' => 1, 'x2' => 9, 'y2' => 1]],
            'nodes' => [
                ['id' => 'n1', 'x' => 2, 'y' => 3, 'label' => 'Front desk', 'user_id' => null],
                ['id' => 'n2', 'x' => 6, 'y' => 3, 'label' => 'Back desk', 'user_id' => null],
            ],
        ]);

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $this->signedInAs($manager)
            ->putJson("/api/v1/monitoring/floor-plans/{$plan->getKey()}/nodes/n1/label",
                ['label' => 'Reception'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Reception');

        $layout = $plan->fresh()->layout;

        $this->assertSame('Reception', $layout['nodes'][0]['label']);

        // The rest of the plan, untouched: the other desk, its position, the
        // wall and the grid. A rename that quietly normalised the document
        // would pass an assertion about the label alone.
        $this->assertSame('Back desk', $layout['nodes'][1]['label']);
        $this->assertSame(2, $layout['nodes'][0]['x']);
        $this->assertSame(6, $layout['nodes'][1]['x']);
        $this->assertCount(1, $layout['walls']);
        $this->assertSame(20, $layout['grid']['cols']);
    }

    public function test_renaming_a_desk_needs_the_edit_grant_and_is_scope_checked(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $companyB = $this->inTenant($alpha['org'], fn () => Company::create([
            'organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true,
        ]));

        // With a node: the suite's default layout carries none, and a rename
        // test against a plan with no desks would 404 for the wrong reason.
        $plan = $this->makePlan($alpha, 'A Office', [
            'assigned_organization_id' => $alpha['org']->id,
            'assigned_company_id' => $companyA->getKey(),
        ], $this->layout([
            ['id' => 'n1', 'x' => 2, 'y' => 3, 'label' => 'Front desk', 'user_id' => null],
        ]));

        $nodeId = $plan->layout['nodes'][0]['id'];
        $path = "/api/v1/monitoring/floor-plans/{$plan->getKey()}/nodes/{$nodeId}/label";

        // Can SEE the map, may not edit it.
        $viewer = $this->companyManager($alpha, $companyA, 'A Viewer', [
            'monitoring.view', 'monitoring.map.view',
        ]);
        $this->signedInAs($viewer)->putJson($path, ['label' => 'Nope'])->assertForbidden();

        // A manager of another company: 404, not 403 — a plan outside reach
        // and a plan that never existed must look identical, or the id space
        // becomes an oracle.
        $companyBMgr = $this->companyManager($alpha, $companyB, 'Company B Mgr');
        $this->signedInAs($companyBMgr)->putJson($path, ['label' => 'Nope'])->assertNotFound();

        // A node id that names nothing on a plan this actor CAN reach: also
        // 404, same reasoning one level down.
        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');
        $this->signedInAs($manager)
            ->putJson("/api/v1/monitoring/floor-plans/{$plan->getKey()}/nodes/no-such-node/label",
                ['label' => 'Nope'])
            ->assertNotFound();

        // CONTROL: the same manager, the same plan, a real node — so every
        // refusal above is its own rule rather than this route being shut.
        $this->signedInAs($manager)->putJson($path, ['label' => 'Reception'])->assertOk();

        // And nothing was renamed by any of the refusals.
        $this->assertSame('Reception', $plan->fresh()->layout['nodes'][0]['label']);
    }

    public function test_seating_somebody_scope_checks_the_person_not_just_the_plan(): void
    {
        // The rule that makes this endpoint safe: `constrainToVisiblePeople`,
        // the same predicate validatedLayout puts on every node assignee. A
        // caller who can reach a PLAN can still be unable to see a person, and
        // seating one they cannot see would put a name on a map they are not
        // entitled to read.
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $companyB = $this->inTenant($alpha['org'], fn () => Company::create([
            'organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true,
        ]));

        $plan = $this->makePlan($alpha, 'A Office', [
            'assigned_organization_id' => $alpha['org']->id,
            'assigned_company_id' => $companyA->getKey(),
        ], $this->layout([
            ['id' => 'n1', 'x' => 2, 'y' => 3, 'label' => 'Computer 1', 'user_id' => null],
        ]));

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');
        $outsider = $this->companyManager($alpha, $companyB, 'Company B Person');
        $path = "/api/v1/monitoring/floor-plans/{$plan->getKey()}/nodes/n1/user";

        // 422, not 404: the plan and the node are both real and reachable, so
        // the refusal is about the PERSON — and the chart can say which.
        $this->signedInAs($manager)
            ->putJson($path, ['user_id' => $outsider->getKey()])
            ->assertStatus(422);

        // CONTROL: somebody they CAN see is seated, so the refusal above is
        // the people-scope rather than this endpoint being shut.
        $this->signedInAs($manager)
            ->putJson($path, ['user_id' => $manager->getKey()])
            ->assertOk();

        $this->assertSame($manager->getKey(), $plan->fresh()->layout['nodes'][0]['user_id']);

        // And clearing works, which is the other half of "set this desk".
        $this->signedInAs($manager)->putJson($path, ['user_id' => null])->assertOk();
        $this->assertNull($plan->fresh()->layout['nodes'][0]['user_id']);
    }
}
