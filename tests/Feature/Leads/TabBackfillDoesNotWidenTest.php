<?php

namespace Tests\Feature\Leads;

use App\Models\Role;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Leads' tab backfill grants panes and never authorities.
 *
 * ── The defect, and why a Leads file could carry it ──────────────
 *
 * `2026_09_17_150000_grant_existing_roles_the_four_lead_sources` walks the
 * WHOLE registry rather than just Leads, deliberately: that is what covers a
 * module declaring its tabs after the first backfill has run. The cost is that
 * it inherits every module's later decisions, including ones taken after it was
 * merged — and none of those decisions are Leads'.
 *
 * `TabDefinition` gained an optional `action`, so a tab can say it IS an
 * existing permission rather than a new one. Training declared three:
 *
 *     new TabDefinition('learn',     'Learn',     action: 'view')
 *     new TabDefinition('dashboard', 'Dashboard', action: 'monitor')
 *     new TabDefinition('settings',  'Settings',  action: 'manage')
 *
 * Unguarded, this file granted `training.monitor` and `training.manage` to
 * every role holding `training.view` — a learner able to author training
 * material and read every colleague's score. Widening, inside the file written
 * to prevent narrowing. Caught by the deploy desk before the cut; Reports and
 * Services fixed the same defect in their own copies the same afternoon.
 *
 * Written against the REGISTRY rather than against Training's three tabs by
 * name, so it keeps working when the next module declares one.
 */
class TabBackfillDoesNotWidenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * No role gains an authority it did not hold, from any module.
     *
     * The subject is every tab in the registry that names an existing action —
     * the set that can be widened into. If that set is empty the test says so
     * and FAILS, rather than passing over a registry where nothing could go
     * wrong: a green assertion over an empty list is how this would stop
     * protecting anything the day somebody removes the last `action:`.
     */
    public function test_a_tab_naming_an_existing_action_is_never_backfilled(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $named = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->tabs as $tab) {
                /*
                 * The module's own view is excluded, and not as tidiness: the
                 * subject role holds it by construction, so "was it granted" is
                 * true whatever the backfill does. Training's `learn` declares
                 * exactly that, and leaving it in fails on the one case that
                 * cannot distinguish before reaching the two that can.
                 */
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
                 * MATERIALISED, not looked up.
                 *
                 * `permissions` rows are written by `ezbizi:sync-permissions`,
                 * a deploy step no test runs, so `training.view` does not exist
                 * in a test database. Looking it up returns null, the role is
                 * granted nothing, the backfill finds no role holding the view,
                 * and the assertion below passes over an empty world — with the
                 * guard REMOVED. Reports hit exactly that and recorded it; this
                 * is the same call the migration itself makes, which is also
                 * why the migration works on a fresh database.
                 */
                $view = app(PermissionCatalogue::class)->materialise($module.'.view');

                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $view->id,
                ]);

                return $role;
            });

            // Run the backfill against a role holding only the view — the state
            // every existing role is in when it runs for real.
            $this->runBackfill();

            $key = $module.'.'.$action;

            // Materialised here too, so an absent row cannot become a silent
            // pass: `if ($permission === null) continue;` is a skip wearing the
            // clothes of a guard, and on an empty database it skips every case
            // and reports success.
            $permission = app(PermissionCatalogue::class)->materialise($key);

            $this->assertFalse(
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists(),
                "the tab backfill granted {$key} to a role holding only {$module}.view — "
                .'a tab that names an existing action is that action, not a new pane',
            );
        }
    }

    /**
     * Leads' four sources ARE still granted — the guard skips named actions
     * and nothing else.
     *
     * The control for the assertion above. Without it, a backfill that granted
     * nothing at all would pass every case there while having silently undone
     * the whole point of the file: nobody loses a source they had this morning.
     */
    public function test_the_four_lead_sources_are_still_granted(): void
    {
        $tenant = $this->provisionTenant('Bravo');

        $role = $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::query()->create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Reader of leads',
            ]);

            $view = app(PermissionCatalogue::class)->materialise('leads.view');

            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $view->id,
            ]);

            return $role;
        });

        $this->runBackfill();

        foreach (['local_services', 'thumbtack', 'website_chat', 'website_form'] as $source) {
            $permission = app(PermissionCatalogue::class)->materialise('leads.'.$source.'.view');

            $this->assertTrue(
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists(),
                "a role holding leads.view did not gain leads.{$source}.view — "
                .'this migration exists so nobody loses a source they had this morning',
            );
        }
    }

    /** The migration's own loop, run against the current registry. */
    private function runBackfill(): void
    {
        $path = 'database/migrations/'
            .'2026_09_17_150000_grant_existing_roles_the_four_lead_sources.php';

        // Required rather than re-implemented, so this asserts the FILE and not
        // a copy of its logic that agrees with itself.
        $migration = require base_path($path);

        $migration->up();
    }
}
