<?php

use App\Models\Permission;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Services declared its two chips after 2026_09_17_090000 had already run.
 *
 * That migration is the one that stopped anybody losing a tab they had this
 * morning, and it is spent: on any box where it has run, a `services.view` role
 * would keep the module and find Catalogue and Settings simply absent, because
 * neither key existed when it swept. So this re-runs the same logic rather than
 * editing a file that has already been applied.
 *
 * ── Why this is not scoped to `services` ─────────────────────────
 *
 * It would be one `if ($module->key !== 'services')` away, and that is the
 * wrong instinct. The original derives its tabs from the REGISTRY precisely so
 * that a module declaring tabs late is covered by re-running the logic, not by
 * somebody remembering to extend a hand-typed list — its own docblock says so.
 * Re-running it whole also catches any other module that declared tabs today
 * without writing a migration of its own, and costs nothing where it has
 * already run: `materialise()` is idempotent and the pivot insert is an
 * `insertOrIgnore`.
 *
 * The narrowing direction is the silent one. A role granted too much gets
 * reported by the person who opens a pane they should not see; a role quietly
 * narrowed shows somebody an absence they cannot tell from a bug. Starting from
 * where every role already stood is the only side of that trade that announces
 * its own mistakes.
 *
 * Administrators hold nothing to add to — `Role::isAdministrator()` bypasses the
 * permission table — so they are untouched here, as they were there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registry = app(ModuleRegistry::class);
        $catalogue = app(PermissionCatalogue::class);

        foreach ($registry->all() as $module) {
            if ($module->tabs === []) {
                continue;
            }

            $viewId = Permission::query()->where('key', "{$module->key}.view")->value('id');

            if ($viewId === null) {
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
                 * A tab that names an action the module ALREADY had is not a
                 * new chip to hand out — it is an existing grant the screen
                 * happens to draw as a tab, and widening it is the opposite of
                 * what this file exists to do.
                 *
                 * `TabDefinition::action()` falls back to `"{key}.view"`, so
                 * an unguarded loop reads a declared `action:` as if it were a
                 * chip key. Training is the live case: it declares
                 * `action: 'view'`, `'monitor'` and `'manage'`, which are three
                 * separate grants by design — Learn is the learner's pane,
                 * monitor is everybody's results, manage is the AUTHORING
                 * surface. Without this clause every role holding
                 * `training.view` would come out of this migration able to
                 * author training material and read every colleague's score.
                 *
                 * Caught by the deploy desk before the cut, and verified here:
                 * this migration is merged and was NOT deployed. Scheduling
                 * wrote the same clause into their copy for `scheduling.edit`
                 * while the risk was still dormant on their branch.
                 */
                if ($tab->namesExistingAction()) {
                    continue;
                }

                // materialise(), not a firstOrCreate here: a permission row
                // carries `module` and `action` beside the key, and it is what
                // lets this run before ezbizi:sync-permissions rather than
                // depending on a deploy step having happened first.
                $permission = $catalogue->materialise("{$module->key}.{$tab->action()}");

                DB::table('permission_role')->insertOrIgnore(
                    $roleIds
                        ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])
                        ->all(),
                );
            }
        }
    }

    public function down(): void
    {
        /*
         * Empty, for the same reason the original's is: the reverse of "grant
         * every tab" is not "revoke every tab". By the time anybody rolls back,
         * an administrator may have narrowed a role deliberately, and sweeping
         * either way would overwrite that decision. The keys stop being declared
         * when the code rolls back and PermissionCatalogue::withImpliedView drops
         * what the registry no longer declares, so the rows go inert by
         * themselves.
         */
    }
};
