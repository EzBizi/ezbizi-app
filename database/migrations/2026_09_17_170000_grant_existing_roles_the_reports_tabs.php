<?php

use App\Models\Permission;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses a Reports tab they had this morning (Jose, 2026-09-17).
 *
 * ── Why this is a SECOND copy and not an edit ────────────────────
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * does exactly this, derived from the registry rather than from a list — which
 * is what makes it coverable by a later module rather than needing an edit.
 * But a migration that has already run does not run again, so a module that
 * declares its tabs afterwards is not covered by it. Settings said so in the
 * brief: copy it with a new timestamp, do not edit the spent one.
 *
 * Editing it would also be wrong in a quieter way: on a fresh database the
 * original runs before Reports has declared anything, and on the box it has
 * already run. Neither copy is redundant on the machine it was written for.
 *
 * ── Safe to run twice ────────────────────────────────────────────
 *
 * The body below is the original, unchanged, and it is idempotent: it
 * re-derives every module's tabs from the registry and `insertOrIgnore`s the
 * pivot rows. Where the first migration already granted a tab, this does
 * nothing. Where a module declared tabs since, it grants them.
 *
 * ── The direction that nobody notices ────────────────────────────
 *
 * Reports has three tabs now and a role that could open all of them this
 * morning holds `reports.view` and nothing else. Without this, that role keeps
 * `reports.view` and loses every pane behind it — and a permission split fails
 * silently in exactly one direction. Granting too much gets reported by
 * whoever sees a pane they should not. Narrowing is somebody opening Reports
 * on Thursday to find the library they used on Wednesday simply gone, with
 * nothing logged and no error to quote.
 *
 * Administrators are untouched and need to be: `Role::isAdministrator()`
 * bypasses the permission table entirely, so they hold nothing to add to.
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
