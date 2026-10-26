<?php

namespace Tests\Feature\Reports;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The tab backfill grants panes and never authorities.
 *
 * ── WHAT WENT WRONG, AND WHY IT COULD ───────────────────────────
 *
 * `2026_09_17_170000_grant_existing_roles_the_reports_tabs` walks the WHOLE
 * registry rather than just Reports, deliberately: that is what covers a module
 * declaring its tabs after the first backfill has already run. The cost is that
 * it inherits every module's later decisions, including ones taken after it was
 * merged.
 *
 * `TabDefinition` gained an optional `action` the day after this file landed,
 * so a tab can now say it IS an existing permission rather than a new one.
 * Training declared three:
 *
 *     new TabDefinition('learn',     'Learn',     action: 'view')
 *     new TabDefinition('dashboard', 'Dashboard', action: 'monitor')
 *     new TabDefinition('settings',  'Settings',  action: 'manage')
 *
 * Unguarded, the loop granted `training.monitor` and `training.manage` to every
 * role holding `training.view` — so a learner came out of it able to author
 * training material and read every colleague's score. Widening, inside the file
 * written to prevent narrowing. Caught by the deploy desk before the cut.
 *
 * ── WHY THIS TEST IS HERE AND NOT IN TRAINING ───────────────────
 *
 * The defect is in this module's migration and the damage is in another's
 * permissions. A test that lived with the damage would be asserting something
 * no Training change can break; this one fails when the file that causes it
 * changes. It is written against the REGISTRY rather than against Training's
 * three tabs by name, so it keeps working when the next module declares one.
 */
class TabBackfillDoesNotWidenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * No role gains an authority it did not hold, from any module.
     *
     * The subject is every tab in the registry that names an existing action —
     * which is the set that can be widened into. If that set is empty the test
     * says so and fails, rather than passing silently against a registry where
     * nothing could go wrong: a green assertion over an empty list is how this
     * stops protecting anything the day somebody removes the last `action:`.
     */
    public function test_a_tab_naming_an_existing_action_is_never_backfilled(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $named = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->tabs as $tab) {
                // The module's OWN view is excluded, and not as tidiness: the
                // subject role holds it by construction, so "was it granted"
                // is true whatever the backfill does. Training's `learn` tab
                // declares exactly that. Leaving it in made the suite fail on
                // the one case that cannot distinguish, before reaching the two
                // that can.
                // A named action that is ALSO another tab's own pane (Inspection's Testing
                // names builder.view, the Builder tab's) is granted by that tab on purpose.
                $siblingPane = collect($module->tabs)->contains(
                    fn ($other) => $other !== $tab && ! $other->namesExistingAction() && $other->action() === $tab->action(),
                );
                if ($tab->namesExistingAction() && $tab->action() !== 'view' && ! $siblingPane) {
                    $named[] = [$module->key, $tab->action()];
                }
            }
        }

        $this->assertNotEmpty(
            $named,
            'no tab in the registry names an existing action, so this test could not '
            .'distinguish a guarded backfill from an unguarded one',
        );

        foreach ($named as [$module, $action]) {
            $role = $this->inTenant($tenant['org'], function () use ($tenant, $module, $action) {
                // Named per TAB, not per module: Training declares three that
                // name an existing action, and a per-module name collides on
                // the roles unique index before the assertion is reached.
                $role = Role::query()->create([
                    'organization_id' => $tenant['org']->id,
                    'name' => 'Watcher of '.$module.' '.$action,
                ]);

                /*
                 * MATERIALISED, not looked up — and the first draft of this
                 * looked it up.
                 *
                 * `permissions` rows are written by `ezbizi:sync-permissions`,
                 * a deploy step no test runs, so `training.view` does not exist
                 * in a test database. Looking it up returned null, the role was
                 * granted nothing, the backfill found no role holding the view,
                 * and the assertion below passed over an empty world — with the
                 * guard REMOVED. The test agreed with the bug.
                 *
                 * This is the same call the migration makes, which is also why
                 * the migration works on a fresh database.
                 */
                $view = app(PermissionCatalogue::class)->materialise($module.'.view');

                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $view->id,
                ]);

                return $role;
            });

            // Re-run the backfill against a role that holds only the view. This
            // is the state every existing role was in when the migration ran.
            $this->runBackfill();

            $key = $module.'.'.$action;

            /*
             * Materialised here too, so an absent row cannot become a
             * silent pass. The first draft did
             * `if ($permission === null) continue;`, which is a skip
             * wearing the clothes of a guard: on a database where nothing
             * is materialised it skipped every case and reported success.
             */
            $permission = app(PermissionCatalogue::class)->materialise($key);

            $this->assertFalse(
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists(),
                "the tab backfill granted {$key} to a role holding only {$module}.view — ".
                'a tab that names an existing action is that action, not a new pane',
            );
        }
    }

    /**
     * THE SAME RUN STILL GRANTS AN ORDINARY TAB.
     *
     * The absences above are satisfied perfectly by a migration that grants
     * NOTHING — one that errored, or skipped every module, or was handed an
     * empty registry. "training.manage is absent" is true in all of those, and
     * a suite of absences cannot tell a working guard from a dead loop.
     *
     * So this asserts the other half in the same conditions: a role holding
     * `reports.view` and nothing else comes out of the backfill holding
     * `reports.library.view`, which is a tab that does NOT name an existing
     * action and therefore must be granted.
     *
     * The control is the deploy desk's, taken from Services' version rather
     * than invented here — they wrote it after a sabotage check came back green
     * against a hazard its worktree could not reach.
     */
    public function test_the_same_run_grants_a_tab_that_is_not_an_existing_action(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $role = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::query()->create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Reports reader',
            ]);

            $view = app(PermissionCatalogue::class)->materialise('reports.view');

            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $view->id,
            ]);

            return $role;
        });

        $this->runBackfill();

        $tab = app(PermissionCatalogue::class)->materialise('reports.library.view');

        $this->assertTrue(
            DB::table('permission_role')
                ->where('role_id', $role->id)
                ->where('permission_id', $tab->id)
                ->exists(),
            'the backfill granted no tab at all — every absence asserted elsewhere in '
            .'this file is satisfied by a migration that does nothing',
        );
    }

    /** The migration's own loop, run again against the current registry. */
    private function runBackfill(): void
    {
        $path = 'database/migrations/'
            .'2026_09_17_170000_grant_existing_roles_the_reports_tabs.php';

        // Required rather than re-implemented, so this asserts the FILE and not
        // a copy of its logic that agrees with itself.
        $migration = require base_path($path);

        $migration->up();
    }
}
