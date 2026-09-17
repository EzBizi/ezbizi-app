<?php

use App\Models\Permission;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The same again, for Leads' four sources.
 *
 * A COPY of 2026_09_17_090000 with a new timestamp, not an edit of it: that one
 * has run on the box, and a migration that has run is spent. Leads declared its
 * tabs after it, so the roles it widened do not hold the four source keys.
 *
 * It re-runs over the WHOLE registry rather than over Leads alone, which is
 * deliberate and free: every grant is an insertOrIgnore, so the modules already
 * covered take no rows, and the modules that declare tabs between the two
 * deploys are picked up without anybody noticing they were missed.
 *
 * Narrowing matters more here than in HR, because Leads has no pane to find
 * empty. A dispatcher with no source keys gets a list that returns nothing and
 * looks exactly like a quiet morning. Everything below is the original, and its
 * reasoning is still the reasoning.
 *
 * ── Original header ──────────────────────────────────────────────
 *
 * Nobody loses a tab they had this morning (Jose, 2026-09-17).
 *
 * Tabs inside a module became grantable one at a time today. Until now a role
 * holding `hr.view` saw all seven of Human Resources' tabs, because the module
 * registry said in as many words that "the tab IS the view permission". After
 * this change, a role holds tabs explicitly — so without this migration every
 * existing role would keep `hr.view` and lose all seven panes behind it on the
 * next deploy.
 *
 * ── The direction that nobody notices ────────────────────────────
 *
 * A permission split can only go wrong in two ways, and they are not
 * symmetrical. Granting too much is visible: somebody opens a tab they should
 * not see and says so. NARROWING is silent — a dispatcher opens Human Resources
 * on Thursday, finds the pane they used on Wednesday simply absent, and has no
 * way to tell a permission change from a bug. Nothing errors, nothing logs, and
 * the only signal is somebody being unable to do their job.
 *
 * So the split starts from exactly where every role already stood: whoever
 * could open the module keeps every tab in it, and an administrator narrows
 * from there deliberately.
 *
 * ── Derived, not listed ──────────────────────────────────────────
 *
 * The tabs come from the registry rather than a hand-written list, so the
 * modules that declare tabs AFTER today are covered by re-running this logic in
 * their own migration rather than by remembering to edit an array here. A list
 * typed into a migration is right on the day it is written.
 *
 * Administrators are untouched and need to be: `Role::isAdministrator()`
 * bypasses the permission table entirely, so they hold nothing to add to.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registry  = app(ModuleRegistry::class);
        $catalogue = app(PermissionCatalogue::class);

        foreach ($registry->all() as $module) {
            if ($module->tabs === []) {
                continue;
            }

            $viewId = Permission::query()->where('key', "{$module->key}.view")->value('id');

            if ($viewId === null) {
                // The module's own view has never been materialised, so no role
                // can be holding it. Nothing to widen.
                continue;
            }

            $roleIds = DB::table('permission_role')
                ->where('permission_id', $viewId)
                ->pluck('role_id');

            if ($roleIds->isEmpty()) {
                continue;
            }

            foreach ($module->tabs as $tab) {
                /*
                 * A TAB THAT NAMES AN EXISTING ACTION IS SKIPPED, AND WITHOUT
                 * THIS LINE THIS FILE WIDENS AUTHORITY.
                 *
                 * Raised by the deploy desk, 2026-09-17, before the first
                 * deploy that would have run it. `TabDefinition::action()`
                 * returns the NAMED action when a tab supplies one, so
                 * Training's three - learn->view, dashboard->monitor,
                 * settings->manage - would have handed `training.monitor` and
                 * `training.manage` to every role holding `training.view`. A
                 * learner comes out able to author training material and read
                 * every colleague's score.
                 *
                 * The premise of this file is "nobody loses what they had". A
                 * minted `{tab}.view` WAS part of the module's view grant until
                 * today, so restoring it preserves. A named action never was:
                 * `monitor` is a disclosure boundary a role holding only `view`
                 * has never held, so granting it is new authority rather than
                 * preservation - the opposite of this file's purpose, in the
                 * file written to prevent the other direction.
                 *
                 * Leads' own four sources are unaffected; it is the
                 * whole-registry sweep that reaches Training. The sweep stays,
                 * because it is the property that makes this file cover a
                 * module declaring tabs after it was written - and that same
                 * property is what let another module's later decision reach
                 * in here.
                 */
                if ($tab->namesExistingAction()) {
                    continue;
                }

                $key = "{$module->key}.{$tab->action()}";

                /*
                 * materialise() rather than a firstOrCreate of my own, and the
                 * first draft of this migration was the latter -- which failed
                 * on "Field 'module' doesn't have a default value", because a
                 * permission row carries `module` and `action` as well as the
                 * key and a hand-written insert here knew about neither.
                 *
                 * It is also what makes this safe to run before
                 * ezbizi:sync-permissions: a migration that depends on a deploy
                 * STEP having happened first works on the box and not on a
                 * fresh database.
                 */
                $permission = $catalogue->materialise($key);

                $rows = $roleIds
                    ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])
                    ->all();

                // insertOrIgnore, because a role may already hold the tab if this
                // runs twice or if somebody granted it between deploy and
                // migrate. The pivot has no timestamps.
                DB::table('permission_role')->insertOrIgnore($rows);
            }
        }
    }

    public function down(): void
    {
        /*
         * Deliberately empty, and this is the honest shape rather than laziness.
         *
         * The reverse of "grant every tab" is not "revoke every tab" — by the
         * time anybody rolls back, an administrator may have narrowed a role on
         * purpose, and re-granting or revoking wholesale would overwrite a
         * decision somebody made. The tab keys stop being declared when the code
         * rolls back, and PermissionCatalogue::withImpliedView drops keys the
         * registry no longer declares, so the rows become inert on their own.
         */
    }
};
