<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Modules\TabDefinition;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A tab inside a module is a permission like any other (Jose, 2026-09-17).
 *
 * His instruction: *"i should be able to grant access by sub tab as well, so for
 * example i want them to see their work hours, but not the onboarding
 * information"*. Until today a module's tabs were covered by its one view
 * permission — the registry said so in as many words — so the smallest grant
 * anybody could make in Human Resources was everybody's contracts and
 * onboarding paperwork.
 *
 * ── What these tests are for ─────────────────────────────────────
 *
 * The mechanism is deliberately tiny: a tab becomes the action `{tab}.view`,
 * and every part of the system that already handles a dotted action then
 * handles it. So what needs proving is not that a new subsystem works — there
 * is no new subsystem — but that the tab key really does travel the whole
 * length of the existing one: declared, merged into `actions`, storable by
 * `materialise()`, and carrying the module's own view with it.
 *
 * A tab that appears in the roles editor and is then refused on save is the
 * failure this guards, and it is invisible until an administrator ticks the box.
 */
class ModuleTabsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function module(array $tabs, array $actions = ModuleDefinition::DEFAULT_ACTIONS): ModuleDefinition
    {
        return new ModuleDefinition(
            key: 'demo',
            label: 'Demo',
            actions: $actions,
            tabs: $tabs,
            automationExemptBecause: 'A fixture, not a module.',
            auditExemptBecause: 'A fixture, not a module.',
        );
    }

    public function test_a_tab_becomes_a_permission_key_on_its_module(): void
    {
        $module = $this->module([new TabDefinition('hours', 'Work hours')]);

        $this->assertContains('hours.view', $module->actions);
        $this->assertContains('demo.hours.view', $module->permissionKeys());

        // The control: the module's own actions are still all there. A merge
        // that REPLACED them would satisfy the two assertions above.
        foreach (['demo.view', 'demo.create', 'demo.edit', 'demo.delete'] as $key) {
            $this->assertContains($key, $module->permissionKeys());
        }
    }

    public function test_a_tab_key_survives_the_whole_catalogue_path(): void
    {
        /*
         * The one that matters. `materialise()` refuses any key whose action is
         * not in `actions`, so a tab held outside that list would be offered by
         * the editor and rejected by the save — and nothing would fail until
         * somebody ticked it.
         *
         * Asked of the REAL registry rather than a fixture, because the fixture
         * is the thing under suspicion: a module built here proves the class
         * merges tabs, not that the registry's own modules reach the catalogue.
         */
        $catalogue = app(PermissionCatalogue::class);

        $declared = $catalogue->keys();
        $tabKeys  = array_values(array_filter($declared, fn (string $k) => substr_count($k, '.') === 2));

        $this->assertNotSame([], $tabKeys, 'No module declares a tab yet, so this proves nothing.');

        foreach ($tabKeys as $key) {
            $this->assertSame($key, $catalogue->materialise($key)->key, "{$key} cannot be stored.");
        }
    }

    public function test_holding_a_tab_implies_the_module_itself(): void
    {
        $catalogue = app(PermissionCatalogue::class);

        $tabKey = collect($catalogue->keys())
            ->first(fn (string $k) => substr_count($k, '.') === 2);

        $this->assertNotNull($tabKey, 'No module declares a tab yet.');

        $module = $catalogue->moduleOf($tabKey);

        // "May open the Work hours tab, cannot reach Human Resources" is not a
        // state the system is allowed to represent — the same §4 invariant that
        // makes `hr.edit` imply `hr.view`, which is why tabs went through
        // `actions` rather than beside them.
        $this->assertContains("{$module}.view", $catalogue->withImpliedView([$tabKey]));
    }

    public function test_the_migration_gives_every_existing_role_the_tabs_it_already_had(): void
    {
        /*
         * The risk this whole change carries, tested directly.
         *
         * A permission split fails silently in one direction only. Granting too
         * much gets reported by whoever sees a pane they should not; NARROWING
         * is a dispatcher opening Human Resources on Thursday to find the tab
         * they used on Wednesday simply gone, with nothing logged and no error
         * to quote.
         *
         * The migration is what stands between every existing role and that. It
         * cannot be proved by the suite merely running -- RefreshDatabase
         * migrates an EMPTY database, where there are no roles to widen, so the
         * migration is a no-op and green says nothing at all. So it is invoked
         * here, by hand, against a role that exists.
         */
        $tenant = $this->provisionTenant('Alpha');

        $role = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => 'Yesterday',
                'is_system'       => false,
            ]);

            // Exactly what such a role held yesterday: the module, and nothing
            // about its tabs, because tabs were not grantable.
            $role->permissions()->attach(
                app(PermissionCatalogue::class)->materialise('hr.view')->id
            );

            return $role;
        });

        $this->assertSame(
            ['hr.view'],
            $this->inTenant($tenant['org'], fn () => $role->fresh()->permissionKeys()),
            'The fixture must start WITHOUT the tabs, or this proves nothing.',
        );

        (require database_path(
            'migrations/2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open.php'
        ))->up();

        $after = $this->inTenant($tenant['org'], fn () => $role->fresh()->permissionKeys());

        foreach (app(\App\Support\Modules\ModuleRegistry::class)->get('hr')->tabs as $tab) {
            $this->assertContains("hr.{$tab->action()}", $after, "The role lost {$tab->label}.");
        }
    }

    public function test_every_role_keeps_the_settings_faces_it_saw_before_they_were_grants(): void
    {
        /*
         * Organization and Settings were drawn for everybody and Integrations
         * for `marketing.view`. A role holding NOTHING is the case that
         * distinguishes: 090000's rule ("holders of the module's view") would
         * give it nothing, and it saw two tabs yesterday.
         */
        $tenant = $this->provisionTenant('Alpha');

        [$plain, $marketer] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $make = fn (string $name) => Role::create([
                'organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false,
            ]);

            $plain    = $make('Plain');
            $marketer = $make('Marketer');
            $marketer->permissions()->attach(app(PermissionCatalogue::class)->materialise('marketing.view')->id);

            return [$plain, $marketer];
        });

        (require database_path(
            'migrations/2026_09_29_440000_grant_every_role_the_settings_faces_it_already_sees.php'
        ))->up();

        $keys = fn (Role $role) => $this->inTenant($tenant['org'], fn () => $role->fresh()->permissionKeys());

        $this->assertSame(
            ['organizations.organization.view', 'organizations.settings.view', 'organizations.view'],
            $keys($plain),
        );
        $this->assertSame(
            ['marketing.view', 'organizations.integrations.view', 'organizations.organization.view',
                'organizations.settings.view', 'organizations.view'],
            $keys($marketer),
        );
    }

    public function test_the_migration_grants_no_action_a_tab_merely_NAMES(): void
    {
        /*
         * The escalation this file nearly shipped, found by the deploy desk
         * before the first deploy that would have run it.
         *
         * `TabDefinition::action()` returns the NAMED action when a tab supplies
         * one, and Training's three name `view`, `monitor` and `manage`. So the
         * loop would have handed `training.monitor` and `training.manage` to
         * every role holding `training.view` — a learner able to author training
         * material and read every colleague's score, granted by the migration
         * written to stop people LOSING access.
         *
         * THREE WAYS A TEST FOR THIS GOES FALSE-GREEN, all three hit by other
         * sessions today, all three avoided here:
         *
         *   1. The subject must hold the module view through
         *      PermissionCatalogue::materialise, because `permissions` rows come
         *      from `ezbizi:sync-permissions` and no test runs it. Attach a key
         *      that does not exist and the role is granted nothing, so the
         *      assertion sails over an empty world.
         *   2. `learn` names `view`, which the subject holds by construction, so
         *      that tab cannot distinguish anything. Only `monitor` and `manage`
         *      can.
         *   3. The declaration has to BE in this tree. A worktree predating
         *      Training's tabs gives the loop nothing to widen, and the run is
         *      green because the defect is unreachable rather than absent — so
         *      this test asserts the fixture's own premise first.
         */
        $registry = app(\App\Support\Modules\ModuleRegistry::class);
        $training = $registry->get('training');

        $named = array_values(array_filter(
            $training?->tabs ?? [],
            fn ($tab) => $tab->namesExistingAction(),
        ));

        $this->assertNotSame(
            [],
            $named,
            'Training declares no named-action tab in this tree, so the widening this test '
            .'guards is unreachable here and a pass means nothing.',
        );

        $tenant = $this->provisionTenant('Foxtrot');

        $role = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => 'Learner',
                'is_system'       => false,
            ]);

            $role->permissions()->attach(
                app(PermissionCatalogue::class)->materialise('training.view')->id
            );

            return $role;
        });

        (require database_path(
            'migrations/2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open.php'
        ))->up();

        $after = $this->inTenant($tenant['org'], fn () => $role->fresh()->permissionKeys());

        foreach ($named as $tab) {
            if ($tab->action() === 'view') {
                continue;   // Held by construction; cannot distinguish.
            }

            $this->assertNotContains(
                "training.{$tab->action()}",
                $after,
                "The migration handed out training.{$tab->action()}, which a role holding only "
                .'the module view has never held.',
            );
        }

        // The control, and it is the half that makes the four assertions above
        // readable: the migration still DID its job in the same run. Without
        // this, a migration that had stopped granting anything at all would
        // satisfy every assertLacks above.
        $this->assertContains('training.view', $after);
        // hr.records.view rather than hr.hours.view since 2026-09-24: this
        // migration derives from the registry when it RUNS, and Work Hours has
        // left HR's tabs, so only a tab HR still declares can be the control.
        $this->assertContains('hr.records.view', $this->inTenant(
            $tenant['org'],
            function () use ($tenant) {
                $other = Role::create([
                    'organization_id' => $tenant['org']->id,
                    'name'            => 'Reader',
                    'is_system'       => false,
                ]);

                $other->permissions()->attach(
                    app(PermissionCatalogue::class)->materialise('hr.view')->id
                );

                (require database_path(
                    'migrations/2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open.php'
                ))->up();

                return $other->fresh()->permissionKeys();
            },
        ));
    }

    public function test_the_migration_widens_nobody_who_could_not_open_the_module(): void
    {
        // The control for the test above. Without it, a migration that granted
        // every tab to every role in the database would pass that one perfectly.
        $tenant = $this->provisionTenant('Bravo');

        $role = $this->inTenant($tenant['org'], fn () => Role::create([
            'organization_id' => $tenant['org']->id,
            'name'            => 'Outsiders',
            'is_system'       => false,
        ]));

        (require database_path(
            'migrations/2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open.php'
        ))->up();

        $this->assertSame(
            [],
            $this->inTenant($tenant['org'], fn () => $role->fresh()->permissionKeys()),
            'A role that could not open Human Resources was handed its tabs.',
        );
    }

    public function test_a_tab_may_name_an_action_the_module_already_has(): void
    {
        /*
         * Training's case, and the reason the option exists at all.
         *
         * Their three tabs were three grants before this mechanism existed:
         * `training.view` is the learner's Learn pane, `training.monitor` is
         * everybody's results, `training.manage` is the authoring surface.
         * Minting `training.dashboard.view` beside `monitor` would have put two
         * names on one authority — and an administrator could then grant the
         * tab without the read, producing a Dashboard that opens and refuses
         * every request inside it.
         */
        $module = $this->module(
            tabs: [new TabDefinition('dashboard', 'Dashboard', action: 'monitor')],
            actions: ['view', 'monitor'],
        );

        $this->assertSame('monitor', $module->tabs[0]->action());

        // NOTHING WAS MINTED. The control for this test is a count: a module
        // that appended `dashboard.view` as well would still satisfy an
        // assertContains on 'monitor'.
        $this->assertSame(['view', 'monitor'], $module->actions);
        $this->assertSame(['demo.view', 'demo.monitor'], $module->permissionKeys());
    }

    public function test_a_tab_naming_an_action_the_module_lacks_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not declare/');

        /*
         * A typo points the editor at a key the module never declares:
         * `materialise()` refuses it on save, so the failure would arrive on
         * somebody's first tick rather than at boot, on a checkbox that looked
         * exactly like the working ones beside it.
         */
        $this->module(
            tabs: [new TabDefinition('dashboard', 'Dashboard', action: 'moniter')],
            actions: ['view', 'monitor'],
        );
    }

    public function test_a_tab_without_a_label_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no label/');

        // The roles editor prints this word on a checkbox. `security.reset` is
        // already drawn to administrators as "Security.reset" for want of one.
        new TabDefinition('hours', '');
    }

    public function test_a_dotted_tab_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contains a dot/');

        // `demo.work.hours.view` would split in the wrong place: moduleOf reads
        // up to the first dot and materialise takes everything after it.
        new TabDefinition('work.hours', 'Work hours');
    }

    public function test_a_tab_that_collides_with_an_action_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both a tab/');

        // Same key, two declarations, and only one of them carries a label —
        // so which one the editor drew would decide what the administrator read.
        $this->module(
            tabs: [new TabDefinition('hours', 'Work hours')],
            actions: ['view', 'hours.view'],
        );
    }

    public function test_the_same_tab_twice_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/twice/');

        $this->module([
            new TabDefinition('hours', 'Work hours'),
            new TabDefinition('hours', 'Hours'),
        ]);
    }
}
