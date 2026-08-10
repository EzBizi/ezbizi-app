<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformModuleFlag;
use App\Models\Role;
use App\Support\Modules\ModuleAvailability;
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

        // Asserted against the registry itself, never a retyped list: a module
        // that declares a new action must appear here with no edit anywhere,
        // and a test carrying its own copy of the answer cannot show that.
        $this->assertSame(
            array_keys($registry->all()),
            array_column($body, 'key'),
        );

        foreach ($body as $module) {
            $definition = $registry->get($module['key']);

            $this->assertSame($definition->label, $module['label']);
            $this->assertSame($definition->actions, array_column($module['actions'], 'action'));
            $this->assertSame($definition->permissionKeys(), array_column($module['actions'], 'key'));
        }
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
