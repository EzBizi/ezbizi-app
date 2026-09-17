<?php

use App\Models\Permission;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses an Inventory chip they had this morning (Jose, 2026-09-17).
 *
 * ── Why this is a second file and not an edit to the first ───────
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * does exactly this, derived from the registry — but it shipped in `08d98e07`
 * before Inventory declared any tabs, so when it ran there was nothing here for
 * it to find. Editing a spent migration is how a change silently never runs on
 * an incrementally-deployed box, and its own docblock names this path: "the
 * modules that declare tabs AFTER today are covered by re-running this logic in
 * their own migration".
 *
 * ── The direction that nobody notices ────────────────────────────
 *
 * Granting too much is visible — somebody opens a pane they should not see and
 * says so. Narrowing is silent: a technician opens Inventory on Friday, finds
 * Stock simply absent, and cannot tell a permission change from a broken build.
 * Without this, every existing role keeps `inventory.view` and loses all four
 * chips on the next deploy, which for a role holding only `inventory.view`
 * means the screen's own "no tabs have been granted" sentence.
 *
 * Administrators need nothing: `Role::isAdministrator()` bypasses the
 * permission table, so they hold no row to add to.
 */
return new class extends Migration
{
    public function up(): void
    {
        $module = app(ModuleRegistry::class)->get('inventory');

        if ($module === null || $module->tabs === []) {
            // Rolled back past the declaration. Nothing to widen.
            return;
        }

        $viewId = Permission::query()->where('key', 'inventory.view')->value('id');

        if ($viewId === null) {
            // Never materialised, so no role holds it.
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $catalogue = app(PermissionCatalogue::class);

        foreach ($module->tabs as $tab) {
            /*
             * materialise() rather than a firstOrCreate: a permission row
             * carries `module` and `action` beside the key, and it is also what
             * lets this run BEFORE ezbizi:sync-permissions rather than
             * depending on a deploy step having already happened — which would
             * work on the box and fail on a fresh database.
             */
            $permission = $catalogue->materialise("inventory.{$tab->action()}");

            // insertOrIgnore: a role may already hold the chip if this runs
            // twice, or if somebody granted it between deploy and migrate. The
            // pivot carries no timestamps.
            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permission->id,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        /*
         * Deliberately empty, matching the migration this follows.
         *
         * The reverse of "grant every chip" is not "revoke every chip": by the
         * time anybody rolls back, an administrator may have narrowed a role on
         * purpose, and revoking wholesale would overwrite that decision. The
         * keys stop being declared when the code rolls back, and
         * PermissionCatalogue::withImpliedView drops keys the registry no
         * longer declares, so the rows go inert on their own.
         */
    }
};
