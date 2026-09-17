<?php

use App\Models\Permission;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses a Scheduling tab they had this morning (Jose, 2026-09-17).
 *
 * ── Why this file exists at all ──────────────────────────────────
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * already does this, derived from the registry — but it is derived AT THE
 * MOMENT IT RUNS, and it runs once. It ships in 0.0.0238 and this module's
 * declaration is not in that cut, so it will sweep HR tonight, find no
 * Scheduling tabs to grant, and never run again. Editing a spent migration is
 * how a change silently never runs on an incrementally-deployed box; its own
 * docblock names this path instead.
 *
 * Inventory, Services and Reports each wrote the same file for the same reason.
 *
 * ── The direction that nobody notices ────────────────────────────
 *
 * Both routes here are ALREADY gated on the new keys — `scheduling/availability`
 * and `scheduling/suggest` on `scheduling.availability.view`, every capacity
 * write on `scheduling.settings.view` — so without this, a role holding
 * `scheduling.view` keeps the rail slot and gets the screen's own "you do not
 * have access to any part of Scheduling yet" sentence.
 *
 * Granting too much is visible: somebody opens a pane they should not see and
 * says so. Narrowing is silent. A dispatcher opens Scheduling on Monday, finds
 * Availability simply absent, and cannot tell a permission change from a broken
 * build — and this module's whole point is that a dispatcher can answer "can we
 * take a job in 75201 on Thursday".
 *
 * Administrators need nothing: `Role::isAdministrator()` bypasses the
 * permission table, so they hold no row to add to.
 */
return new class extends Migration
{
    public function up(): void
    {
        $module = app(ModuleRegistry::class)->get('scheduling');

        if ($module === null || $module->tabs === []) {
            // Rolled back past the declaration. Nothing to widen.
            return;
        }

        $viewId = Permission::query()->where('key', 'scheduling.view')->value('id');

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
             * A tab naming an action the module ALREADY had is skipped, and
             * this guard is the one thing here that is not a copy of
             * Inventory's file. All three of these mint their own key today, so
             * it is dormant — but if somebody later points a tab at, say,
             * `scheduling.edit`, this loop would hand editing to every role
             * that can only VIEW. That is the widening direction, which is the
             * one this file exists to avoid in the first place.
             */
            if ($tab->namesExistingAction()) {
                continue;
            }

            /*
             * materialise() rather than firstOrCreate: a permission row carries
             * `module` and `action` beside the key, and it is what lets this run
             * BEFORE ezbizi:sync-permissions rather than depending on a deploy
             * step having already happened — which would work on the box and
             * fail on a fresh database.
             */
            $permission = $catalogue->materialise("scheduling.{$tab->action()}");

            // insertOrIgnore: a role may already hold the tab if this runs
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
         * The reverse of "grant every tab" is not "revoke every tab": by the
         * time anybody rolls back, an administrator may have narrowed a role on
         * purpose, and revoking wholesale would overwrite that decision. The
         * keys stop being declared when the code rolls back, and
         * PermissionCatalogue::withImpliedView drops keys the registry no
         * longer declares, so the rows go inert on their own.
         */
    }
};
