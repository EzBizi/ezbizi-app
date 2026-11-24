<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformModuleFlag;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Roles, and everything that stops one being dangerous (plan §4).
 *
 * Until this endpoint existed an organization was permanently a single
 * Administrator: `Role::create` appeared once, in ProvisionOrganization, and
 * `permission_role` was written nowhere at all. So every rule below is being
 * tested for the first time — none of them had a code path that could reach it.
 */
class RolesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the discriminator (build step 1) ─────────────────────────

    public function test_renaming_the_administrator_role_does_not_revoke_its_permissions(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // The old isAdministrator() matched on the English display name, so this
        // one write used to evaporate every implicit permission the role had —
        // and the person who did it could no longer reach the screen to undo it.
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = $alpha['admin']->role;
            $role->forceFill(['name' => 'Propietario'])->save();

            $this->assertTrue($role->fresh()->isAdministrator());
            $this->assertTrue($alpha['admin']->fresh()->hasPermission('customers.delete'));
        });
    }

    /**
     * Declare a requirement to test the MECHANISM with.
     *
     * No shipped module declares one. `training.assign` did from 0.0.0180 to
     * 0.0.0182 and it was removed because the premise was measured wrong — see
     * ModuleServiceProvider's training block. Pinning these rules to whichever
     * module happens to carry a requirement this month is how the last version
     * of this test died; a module of its own outlives the policy.
     */
    private function moduleWithARequirement(): void
    {
        app(ModuleRegistry::class)->register(new ModuleDefinition(
            key: 'reqdemo',
            label: 'Requirement Demo',
            actions: ['view', 'watch', 'act'],
            actionRequires: ['act' => ['reqdemo.watch']],
            automationExemptBecause: 'A fixture, not a feature. It emits nothing.',
        ));
    }

    public function test_a_permission_cannot_be_granted_without_the_one_it_needs(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->moduleWithARequirement();

        // Registered BEFORE the first request: a route keeps one controller and
        // its injected catalogue for the whole test, so a module added after the
        // first call is never seen.
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Supervisor', 'permissions' => ['reqdemo.act'],
        ])->assertStatus(422)->assertJsonPath(
            'errors.permissions.0',
            fn (string $m) => str_contains($m, 'reqdemo.act needs reqdemo.watch'),
        );

        $this->inTenant($alpha['org'], function () {
            $this->assertNull(Role::where('name', 'Supervisor')->first());
        });
    }

    public function test_the_same_grant_with_its_requirement_is_accepted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->moduleWithARequirement();

        // The control for the refusal above. Without it, a check that refused
        // every save containing `reqdemo.act` — or every save at all — would
        // pass the test that matters and break the feature.
        $created = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name'        => 'Supervisor',
            'permissions' => ['reqdemo.act', 'reqdemo.watch'],
        ])->assertCreated()->json('data');

        $this->assertContains('reqdemo.act', $created['permissions']);
        $this->assertContains('reqdemo.watch', $created['permissions']);
    }

    public function test_an_action_carries_what_it_requires_into_the_catalogue(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->moduleWithARequirement();

        // The editor cannot warn about a rule it is never told. The second
        // assertion is the half that matters: a payload saying `requires`
        // everywhere would be as useless as one saying it nowhere.
        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $demo  = collect($modules)->firstWhere('key', 'reqdemo');
        $act   = collect($demo['actions'])->firstWhere('action', 'act');
        $watch = collect($demo['actions'])->firstWhere('action', 'watch');

        $this->assertSame(['reqdemo.watch'], $act['requires']);
        $this->assertSame([], $watch['requires']);
    }

    /** Omitted until Settings › Activity read it (2026-10-16); now it opens that tab. */
    public function test_the_editor_offers_the_audit_log_now_a_screen_reads_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $keys = collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules'))
            ->pluck('key');

        $this->assertContains('roles', $keys, 'control: a Settings module is still offered');
        $this->assertContains('audit', $keys);
    }

    public function test_an_unknown_permission_is_refused_by_name_not_dropped(): void
    {
        // Desk, 2026-11-23: `reviews.reply` came back 200 and was ignored.
        $alpha = $this->provisionTenant('Alpha');
        $role = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/roles', ['name' => 'Desk', 'permissions' => ['customers.view']])
            ->assertCreated()->json('data');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/roles/{$role['id']}", ['permissions' => ['customers.view', 'reviews.reply']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions')
            ->assertJsonFragment(['permissions' => ['These are not permissions EzBizi has, so nothing was saved: reviews.reply.']]);

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/roles', ['name' => 'Other', 'permissions' => ['nope.view']])
            ->assertUnprocessable();

        // Nothing was saved, and a real key still saves (control).
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/roles/{$role['id']}", ['permissions' => ['customers.view', 'jobs.view']])
            ->assertOk();
    }

    public function test_no_shipped_module_declares_a_requirement_by_accident(): void
    {
        // The mechanism stays and the client renders it, so nothing stops a
        // module quietly acquiring a requirement. Every one costs an
        // administrator a grant they did not ask for, and `training.assign`
        // showed the reasoning can be wrong in a way that hides itself. A new
        // entry here should be a decision somebody argued for, not a diff
        // nobody read.
        //
        // This asserted an EMPTY list until 2026-09-10, when Reports shipped
        // the first requirement anybody had argued for. The guard worked
        // exactly as intended and caught it on the release gate -- the desk had
        // read and accepted the argument and then forgotten the guard existed.
        //
        // So it is an allowlist now, not an emptiness check, and the reasoning
        // for each entry lives here rather than in a merge message nobody
        // re-reads. Adding a key still costs an argument; it just has somewhere
        // to be written down.
        //
        //   reports  `build` and `export` both require `reports.view`. Neither
        //            is reachable without it -- the tab is what you compose and
        //            export FROM -- so the requirement describes the product
        //            rather than restricting it. Contrast `training.assign`,
        //            which was removed because an automation could reach it
        //            without `training.monitor`: the premise was made true by
        //            the refusal itself.
        //
        //   work_hours  `all` requires `work_hours.general.view` (it widens
        //            that tab), and `manage` requires `work_hours.all`: without
        //            it the only rows reachable are your own, so manage-alone
        //            is editing your own paid hours (Jose, 2026-09-24). These
        //            were HR's `hours.all` / `hours.correct` until Work Hours
        //            became its own module the same day; the argument moved
        //            with them and HR no longer declares any requirement.
        //   estimates  every action requires `jobs.financials` (2026-09-29).
        //            EstimatePolicy refuses each one without it -- an estimate
        //            is money, and Jose ruled money is "a financials toggle" --
        //            and the Jobs screen draws the Estimates tab only with both.
        //            No automation action reaches an estimate, so unlike
        //            `training.assign` nothing gets past the policy: the
        //            requirement describes the product rather than making its
        //            own premise true.
        //   field    `listen` requires `field.recordings.view` (2026-10-16).
        //            Other people's recordings are listed and played only in
        //            the Recordings tab, so listen without the tab is a grant
        //            nothing can spend; the requirement describes the product.
        // Roles audit 2026-11-09: the screens' own hidden requirements, declared.
        $argued = ['company', 'estimates', 'field', 'guidelines', 'inspection', 'leads', 'organizations', 'project_board', 'reports', 'work_hours'];

        $declaring = [];

        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            if ($module->actionRequires !== []) {
                $declaring[] = $key;
            }
        }

        sort($declaring);

        $this->assertSame($argued, $declaring);
    }

    public function test_a_role_payload_cannot_make_itself_administrator(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name'             => 'Sneaky',
            'is_administrator' => true,
            'is_system'        => true,
        ])->assertCreated();

        $this->inTenant($alpha['org'], function () {
            $role = Role::where('name', 'Sneaky')->firstOrFail();

            // Unfillable, so mass assignment cannot reach it however the payload
            // is shaped. This is a structural guarantee, not a policy check that
            // a second endpoint might forget to repeat.
            $this->assertFalse($role->isAdministrator());
            $this->assertFalse($role->is_system);
        });
    }

    // ── the catalogue (build step 3) ─────────────────────────────

    public function test_the_catalogue_is_the_module_registry(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $registry = app(ModuleRegistry::class);

        $shown = array_values(array_filter(
            $registry->all(),
            fn (ModuleDefinition $module) => $module->rolesGroup !== ModuleDefinition::ROLES_OMIT,
        ));

        $this->assertEqualsCanonicalizing(
            array_map(fn (ModuleDefinition $m) => $m->key, $shown),
            array_column($body, 'key'),
            'Every grantable module is listed — omitted ones are not a tab.',
        );

        foreach ($body as $module) {
            $definition = $registry->get($module['key']);

            $this->assertSame($definition->label, $module['label']);
            $this->assertSame($definition->actions, array_column($module['actions'], 'action'));
            $this->assertSame($definition->permissionKeys(), array_column($module['actions'], 'key'));
        }
    }

    public function test_the_catalogue_follows_the_sidebar_then_organization_then_settings(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $keys = array_column(
            $this->signedInAs($alpha['admin'])
                ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules'),
            'key',
        );

        $this->assertSame('dashboard', $keys[0]);
        $this->assertContains('hr', $keys);
        $this->assertContains('companies', $keys);
        $this->assertNotContains(
            'users',
            $keys,
            'Users is not a tab — Human Resources is. Granting users.* would look like a tab that does not exist.',
        );

        $this->assertLessThan(
            array_search('companies', $keys, true),
            array_search('automations', $keys, true),
            'Work tabs come first, then Organization grants.',
        );
        $this->assertLessThan(
            array_search('roles', $keys, true),
            array_search('companies', $keys, true),
            'Organization grants come before Settings grants.',
        );

        $groups = array_column(
            $this->signedInAs($alpha['admin'])
                ->getJson('/api/v1/roles/catalogue')->json('modules'),
            'group',
            'key',
        );

        $this->assertSame('tab', $groups['customers']['key']);
        $this->assertSame('organization', $groups['companies']['key']);
        $this->assertSame('Organization', $groups['franchises']['label']);
        $this->assertSame('settings', $groups['roles']['key']);
        $this->assertStringContainsString('no Companies', $groups['companies']['hint']);
    }

    public function test_every_module_declares_a_view_action(): void
    {
        // The tab IS the view permission (§4), so a module without one could be
        // neither reached nor switched on. ModuleDefinition refuses it at boot;
        // this is the same statement asked of what is actually registered.
        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            $this->assertContains('view', $module->actions, "Module '{$key}' declares no view.");
        }
    }

    public function test_a_platform_disabled_module_is_listed_as_unavailable_rather_than_hidden(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        app(ModuleAvailability::class)->setState(
            'jobs', PlatformModuleFlag::DISABLED, reason: 'Rebuilding the scheduler'
        );

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $jobs = collect($modules)->firstWhere('key', 'jobs');

        // Shown, and shown as off. Hiding it leaves an administrator unable to
        // tell "we don't have this" from "I've lost the right to configure it".
        $this->assertNotNull($jobs);
        $this->assertFalse($jobs['available']);
        $this->assertSame([], array_values(array_filter(array_column($jobs['actions'], 'grantable'))));
    }

    // ── creating and editing (build step 2) ──────────────────────

    public function test_an_organization_builds_its_own_role(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Dispatcher', 'permissions' => ['jobs.view', 'jobs.edit'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['jobs.edit', 'jobs.view']);

        $listed = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles')->assertOk()->json('data');

        $dispatcher = collect($listed)->firstWhere('name', 'Dispatcher');

        $this->assertSame(0, $dispatcher['user_count']);
        $this->assertFalse($dispatcher['is_administrator']);

        // Administrator reports the whole catalogue rather than its stored set,
        // which is empty — it holds everything implicitly.
        $administrator = collect($listed)->firstWhere('is_administrator', true);
        $this->assertSame(1, $administrator['user_count']);
        $this->assertSame(app(ModuleRegistry::class)->allPermissionKeys(), $administrator['permissions']);
    }

    /** UI queue 111: the role cards draw who holds each role. */
    public function test_each_role_names_up_to_eight_of_its_holders_alphabetically(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $crew  = $this->userWithRole($alpha, 'Crew 05');
        $this->userWithRole($alpha, 'Zed');

        // Nine holders written out of order, so neither the cap nor the order
        // can come out right by accident.
        $this->inTenant($alpha['org'], function () use ($alpha, $crew) {
            foreach ([9, 3, 1, 7, 2, 8, 4, 6] as $n) {
                User::create([
                    'organization_id' => $alpha['org']->id, 'role_id' => $crew->role_id,
                    'name' => sprintf('Crew %02d', $n), 'email' => "crew{$n}@alpha.test",
                    'password' => 'a-real-password', 'is_active' => true,
                ]);
            }
        });

        $listed = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/roles')->assertOk()->json('data'));

        $crewRole = $listed->firstWhere('name', 'Crew 05');
        $this->assertSame(9, $crewRole['user_count']);
        $this->assertSame(
            ['Crew 01', 'Crew 02', 'Crew 03', 'Crew 04', 'Crew 05', 'Crew 06', 'Crew 07', 'Crew 08'],
            array_column($crewRole['people'], 'name'),
        );
        $this->assertSame($crew->id, $crewRole['people'][4]['id']);

        $this->assertSame([['id' => $alpha['admin']->id, 'name' => 'Alpha Admin']], $listed->firstWhere('is_administrator', true)['people']);
        $this->assertSame(['Zed'], array_column($listed->firstWhere('name', 'Zed')['people'], 'name'));
    }

    public function test_role_names_are_unique_within_an_organization_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/roles', ['name' => 'Dispatcher'])->assertCreated();

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/roles', ['name' => 'Dispatcher'])->assertStatus(422);

        // Two tenants may each have a Dispatcher and neither has heard of the other.
        $this->signedInAs($bravo['admin'])
            ->postJson('/api/v1/roles', ['name' => 'Dispatcher'])->assertCreated();
    }

    // ── the implied-view rule (build step 5) ─────────────────────

    public function test_granting_an_action_implies_the_modules_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Editor', 'permissions' => ['customers.edit'],
        ])->assertCreated()->json('data');

        // "May edit, tab hidden" is not a state the data can hold — which is why
        // §4 shows one switch and not two. Enforced here, not in the client: a
        // second client, an import or a curl request would each have to
        // re-implement it, and one of them would get it wrong.
        $this->assertSame(['customers.edit', 'customers.view'], $created['permissions']);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$created['id']}", [
            'permissions' => ['customers.delete'],
        ])->assertOk()->assertJsonPath('data.permissions', ['customers.delete', 'customers.view']);
    }

    public function test_removing_the_view_removes_the_whole_module(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Editor', 'permissions' => ['customers.edit', 'jobs.view'],
        ])->assertCreated()->json('data');

        // The other half of the same invariant: switching the tab off cannot
        // leave the actions behind it granted. Sending neither is how the
        // collapsed switch is turned off.
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'permissions' => ['jobs.view'],
        ])->assertOk()->assertJsonPath('data.permissions', ['jobs.view']);
    }

    // ── Administrator is immutable, in every direction ───────────

    public function test_administrator_cannot_be_renamed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id    = $this->administratorRoleId($alpha);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/roles/{$id}", ['name' => 'Owner'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Administrator cannot be changed'));

        $this->inTenant($alpha['org'], fn () => $this->assertSame(
            Role::ADMINISTRATOR, Role::find($id)->name
        ));
    }

    public function test_administrator_cannot_have_permissions_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id    = $this->administratorRoleId($alpha);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/roles/{$id}", ['permissions' => ['customers.view']])
            ->assertForbidden();

        // It holds everything implicitly, so a partial list would have been read
        // as "these four and nothing else" — the lockout the owner named.
        $this->inTenant($alpha['org'], fn () => $this->assertTrue(
            $alpha['admin']->fresh()->hasPermission('customers.delete')
        ));
    }

    public function test_administrator_cannot_be_deleted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id    = $this->administratorRoleId($alpha);

        $this->signedInAs($alpha['admin'])
            ->deleteJson("/api/v1/roles/{$id}")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'cannot be deleted'));

        $this->inTenant($alpha['org'], fn () => $this->assertNotNull(Role::find($id)));
    }

    // ── no privilege escalation ──────────────────────────────────

    public function test_nobody_can_grant_a_permission_they_do_not_hold(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.create', 'roles.edit', 'customers.view', 'customers.create',
        ]);

        $this->signedInAs($supervisor)->postJson('/api/v1/roles', [
            'name' => 'Overreach', 'permissions' => ['customers.view', 'customers.delete'],
        ])->assertStatus(422)->assertJsonPath(
            'errors.permissions.0',
            fn (string $m) => str_contains($m, 'customers.delete') && str_contains($m, 'cannot grant'),
        );

        $this->inTenant($alpha['org'], fn () => $this->assertNull(Role::where('name', 'Overreach')->first()));
    }

    public function test_what_a_caller_may_grant_is_what_they_hold(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.create', 'roles.edit', 'customers.view', 'customers.create',
        ]);

        // Inside their own set, so it goes through.
        $this->signedInAs($supervisor)->postJson('/api/v1/roles', [
            'name' => 'Intake', 'permissions' => ['customers.create'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['customers.create', 'customers.view']);
    }

    public function test_permissions_the_caller_cannot_see_are_neither_removed_nor_kept_by_accident(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Team', 'permissions' => ['customers.view', 'customers.create', 'customers.delete'],
        ])->assertCreated()->json('data');

        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.edit', 'customers.view', 'customers.create',
        ]);

        $this->signedInAs($supervisor)->patchJson("/api/v1/roles/{$role['id']}", [
            'permissions' => ['customers.view'],
        ])->assertOk()->assertJsonPath('data.permissions', ['customers.delete', 'customers.view']);

        // customers.create went, because the supervisor holds it and left it out.
        // customers.delete stayed, because their editor never drew that checkbox
        // — an omission from a list they could not see is not a decision to
        // remove it, and treating it as one lets a smaller role quietly strip a
        // larger one.
    }

    // ── deleting ─────────────────────────────────────────────────

    public function test_a_role_somebody_holds_cannot_be_deleted(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $roleId = $this->inTenant($alpha['org'], fn () => $viewer->role_id);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/roles/{$roleId}")
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', fn (string $m) => str_contains($m, '1 person'));

        // users.role_id is nullable with ON DELETE SET NULL, so the delete would
        // not have failed — it would have left this person signing in to an
        // empty application with nothing on screen saying why.
        $this->inTenant($alpha['org'], function () use ($viewer, $roleId) {
            $this->assertNotNull(Role::find($roleId));
            $this->assertSame($roleId, $viewer->fresh()->role_id);
        });
    }

    public function test_an_unused_role_deletes(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Temporary', 'permissions' => ['jobs.view'],
        ])->assertCreated()->json('data');

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/roles/{$role['id']}")->assertOk();

        $this->inTenant($alpha['org'], fn () => $this->assertNull(Role::find($role['id'])));
    }

    // ── audit (§2.2) ─────────────────────────────────────────────

    public function test_a_permission_change_records_what_was_added_and_removed(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Dispatcher', 'permissions' => ['jobs.view', 'jobs.create'],
        ])->assertCreated()->json('data');

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'name' => 'Dispatch lead', 'permissions' => ['jobs.view', 'jobs.edit'],
        ])->assertOk();

        $events = AuditEvent::where('target_id', $role['id'])->pluck('changes', 'action');

        $this->assertSame(
            ['jobs.create', 'jobs.view'],
            AuditEvent::where('action', 'role.created')->value('metadata')['permissions'],
        );

        // The one entry somebody will actually need. "Permissions changed" with
        // no list cannot answer the only question ever asked of it.
        $this->assertSame(['jobs.edit'], $events['role.permissions_changed']['added']);
        $this->assertSame(['jobs.create'], $events['role.permissions_changed']['removed']);

        $this->assertSame('Dispatch lead', $events['role.updated']['after']['name']);
    }

    // ── /me carries the contract ─────────────────────────────────

    public function test_me_reports_exactly_what_the_role_holds(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.create', 'jobs.view']);

        $body = $this->signedInAs($viewer)->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame(
            ['customers.create', 'customers.view', 'jobs.view'],
            $body['permissions'],
        );

        $modules = collect($body['modules'])->keyBy('key');

        $this->assertTrue($modules['customers']['reachable']);
        $this->assertTrue($modules['jobs']['reachable']);
        $this->assertFalse($modules['companies']['reachable']);
        $this->assertTrue($modules['companies']['available']);
    }

    public function test_me_gives_an_administrator_the_catalogue_and_never_a_wildcard(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($alpha['admin'])->getJson('/api/v1/me')->assertOk()->json('data');

        // A wildcard would force every consumer to special-case a role, and a
        // client special-casing a role name is the same bug the server had.
        $this->assertSame(app(ModuleRegistry::class)->allPermissionKeys(), $body['permissions']);
        $this->assertNotContains('*', $body['permissions']);

        foreach ($body['modules'] as $module) {
            $this->assertTrue($module['reachable'], "Administrator cannot reach {$module['key']}.");
        }
    }

    public function test_a_platform_disabled_module_is_unreachable_even_when_the_role_holds_it(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['jobs.view', 'customers.view']);

        app(ModuleAvailability::class)->setState(
            'jobs', PlatformModuleFlag::DISABLED, reason: 'Rebuilding the scheduler'
        );

        $body = $this->signedInAs($viewer)->getJson('/api/v1/me')->assertOk()->json('data');

        $jobs = collect($body['modules'])->firstWhere('key', 'jobs');

        $this->assertFalse($jobs['available']);
        $this->assertFalse($jobs['reachable']);

        // And it drops out of `permissions` too, because that list must be
        // exactly what hasPermission() would answer true for — otherwise the
        // client draws a control the server then refuses.
        $this->assertNotContains('jobs.view', $body['permissions']);
        $this->assertContains('customers.view', $body['permissions']);
    }

    // ── the point of all of it: a real endpoint refuses ──────────

    public function test_a_restricted_role_is_refused_by_a_real_endpoint(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $reader  = $this->userWithRole($alpha, 'Reader', ['companies.view']);
        $builder = $this->userWithRole($alpha, 'Builder', ['companies.view', 'companies.create']);

        $this->signedInAs($reader)->getJson('/api/v1/companies')->assertOk();

        $this->signedInAs($reader)->postJson('/api/v1/companies', [
            'name' => 'Everdoors', 'franchise' => ['name' => 'Connecticut'],
        ])->assertForbidden();

        // The positive control. Without it this test passes just as well against
        // an endpoint that refuses everybody, which is how 32 of 33 authorize()
        // calls came to be deletable with the suite still green.
        $this->signedInAs($builder)->postJson('/api/v1/companies', [
            'name' => 'Everdoors', 'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();
    }

    public function test_the_roles_screen_itself_is_behind_a_permission(): void
    {
        $alpha    = $this->provisionTenant('Alpha');
        $outsider = $this->userWithRole($alpha, 'Outsider', ['customers.view']);

        $this->signedInAs($outsider)->getJson('/api/v1/roles')->assertForbidden();
        $this->signedInAs($outsider)->getJson('/api/v1/roles/catalogue')->assertForbidden();
        $this->signedInAs($outsider)->postJson('/api/v1/roles', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_role_from_another_organization_is_not_found(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $theirs = $this->administratorRoleId($bravo);

        // Tenant scoping answers before the policy does, so it is a 404 rather
        // than a 403 — Alpha is not told that role exists at all.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/roles/{$theirs}", ['name' => 'Theirs'])
            ->assertNotFound();
    }

    // ── helpers ──────────────────────────────────────────────────

    private function administratorRoleId(array $tenant): string
    {
        return $this->inTenant($tenant['org'], fn () => $tenant['admin']->fresh()->role_id);
    }
}
