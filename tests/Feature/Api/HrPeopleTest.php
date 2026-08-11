<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Records tab, and the two things about a person that can change (§4).
 *
 * `users.role_id` is written here for the first time in the life of this
 * application, which means §4's last-administrator rule is reachable for the
 * first time too — it has been a line in a plan since the foundation with no
 * code path that could break it.
 */
class HrPeopleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the list ─────────────────────────────────────────────────

    public function test_the_list_is_filtered_to_the_callers_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $company->id,
                'name'            => 'Other F1',
            ]);
        });

        $mine   = $this->userWithRole($alpha, 'Nearby', ['customers.view'], onlyAt: $alpha['franchise']);
        $theirs = $this->userWithRole($alpha, 'Distant', ['customers.view'], onlyAt: $elsewhere);

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'customers.view'], onlyAt: $alpha['franchise'],
        );

        $names = $this->signedInAs($manager)->getJson('/api/v1/hr/people')
            ->assertOk()->json('data.*.name');

        sort($names);

        // Themselves and the person in their franchise. Not the administrator,
        // who stands above them, and not the person in another company.
        $this->assertSame(['Branch Manager', 'Nearby'], $names);
        $this->assertNotContains($theirs->name, $names);
        $this->assertNotContains($alpha['admin']->name, $names);
        $this->assertNotNull($mine);
    }

    public function test_deactivated_people_stay_in_the_list(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Leaver', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // §5: deactivate is not delete, and the record survives — a Records tab
        // that hid them would be a list of current staff pretending otherwise.
        $emails = $this->signedInAs($alpha['admin'])->getJson('/api/v1/hr/people')
            ->assertOk()->json('data.*.email');

        $this->assertContains($person->email, $emails);

        $active = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/people?active_only=1')->assertOk()->json('data.*.email');

        $this->assertNotContains($person->email, $active);
    }

    public function test_a_person_outside_your_reach_cannot_be_changed(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $company->id,
                'name'            => 'Other F1',
            ]);
        });

        $distant = $this->userWithRole($alpha, 'Distant', ['customers.view'], onlyAt: $elsewhere);
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.edit', 'customers.view'],
            onlyAt: $alpha['franchise'],
        );

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/people/{$distant->id}", ['is_active' => false])
            ->assertForbidden();
    }

    // ── the field nothing used to write ──────────────────────────

    public function test_a_role_can_be_given_to_somebody(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $dispatcher = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id, 'name' => 'Dispatcher', 'is_system' => false,
            ]);
            $role->syncPermissionKeys(['jobs.view', 'jobs.edit']);

            return $role;
        });

        $person = $this->userWithRole($alpha, 'Junior', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['role_id' => $dispatcher->id])
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Dispatcher');

        // The permissions moved with it, which is the entire point of the field.
        $this->inTenant($alpha['org'], function () use ($person) {
            $person = $person->fresh();

            $this->assertTrue($person->hasPermission('jobs.edit'));
            $this->assertFalse($person->hasPermission('customers.view'));
        });
    }

    public function test_you_cannot_give_a_role_that_reaches_further_than_your_own(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $manager = $this->userWithRole($alpha, 'Branch Manager', ['hr.view', 'hr.edit']);
        $person  = $this->userWithRole($alpha, 'Junior', []);

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/people/{$person->id}", ['role_id' => $alpha['admin']->role_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    // ── last-administrator protection (§4) ───────────────────────

    public function test_the_last_administrator_cannot_be_deactivated(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // Somebody else with hr.edit, so the refusal is about the FLOOR rather
        // than about deactivating yourself.
        $manager = $this->userWithRole($alpha, 'Branch Manager', ['hr.view', 'hr.edit']);

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/people/{$alpha['admin']->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->inTenant($alpha['org'], fn () => $this->assertTrue($alpha['admin']->fresh()->is_active));
    }

    public function test_the_last_administrator_cannot_be_moved_off_the_administrator_role(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $lesser = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id, 'name' => 'Observer', 'is_system' => false,
            ]);
            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });

        // The administrator demoting themselves — the way nobody thinks of, and
        // the one that leaves an organization with no way back.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$alpha['admin']->id}", ['role_id' => $lesser->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->inTenant($alpha['org'], fn () => $this->assertTrue(
            $alpha['admin']->fresh()->isAdministrator(),
        ));
    }

    public function test_an_administrator_may_step_down_once_there_is_a_second_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$secondAdmin, $lesser] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $observer = Role::create([
                'organization_id' => $alpha['org']->id, 'name' => 'Observer', 'is_system' => false,
            ]);
            $observer->syncPermissionKeys(['customers.view']);

            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $alpha['admin']->role_id,
                'name'            => 'Second Admin',
                'email'           => 'second@alpha.test',
                'password'        => 'a-real-password',
            ]);

            \App\Models\Assignment::create([
                'organization_id'          => $alpha['org']->id,
                'user_id'                  => $user->id,
                'assigned_organization_id' => $alpha['org']->id,
            ]);

            return [$user, $observer];
        });

        $this->assertNotNull($secondAdmin);

        // The floor is "at least one", not "never fewer than you started with".
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$alpha['admin']->id}", ['role_id' => $lesser->id])
            ->assertOk();
    }

    public function test_you_cannot_switch_off_your_own_access(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // Two administrators, so the last-administrator floor is not what
        // refuses this — the refusal being tested is the self-lockout.
        $second = $this->inTenant($alpha['org'], function () use ($alpha) {
            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $alpha['admin']->role_id,
                'name'            => 'Second Admin',
                'email'           => 'second@alpha.test',
                'password'        => 'a-real-password',
            ]);

            \App\Models\Assignment::create([
                'organization_id'          => $alpha['org']->id,
                'user_id'                  => $user->id,
                'assigned_organization_id' => $alpha['org']->id,
            ]);

            return $user;
        });

        $this->signedInAs($second)
            ->patchJson("/api/v1/hr/people/{$second->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    }

    // ── the triggers (§5.5) ──────────────────────────────────────

    public function test_switching_somebody_off_and_on_raises_both_triggers(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Leaver', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => false])->assertOk();

        $deactivated = AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'user.deactivated')->first();

        $this->assertNotNull($deactivated, 'user.deactivated was declared as raised and was not.');
        $this->assertSame($person->id, $deactivated->subject_id);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => true])->assertOk();

        // The same event acceptance raises. A rule saying "when somebody's
        // account starts working, tell their manager" has to fire both times or
        // it is wrong half of them — which is why the registry's description was
        // widened rather than a fourth trigger added.
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'user.activated')->count());
    }

    public function test_an_unchanged_patch_raises_nothing(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Steady', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => true])
            ->assertOk();

        // Submitting a form without touching it is not an event.
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()
            ->whereIn('event_key', ['user.activated', 'user.deactivated'])->count());
    }

    // ── the module gate ──────────────────────────────────────────

    public function test_the_records_tab_needs_its_own_permission(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Junior', ['customers.view']);

        $outsider = $this->userWithRole($alpha, 'Outsider', ['customers.view']);

        $this->signedInAs($outsider)->getJson('/api/v1/hr/people')->assertForbidden();

        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view']);

        $this->signedInAs($reader)->getJson('/api/v1/hr/people')->assertOk();

        // Reading is not editing.
        $this->signedInAs($reader)
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => false])
            ->assertForbidden();
    }
}
