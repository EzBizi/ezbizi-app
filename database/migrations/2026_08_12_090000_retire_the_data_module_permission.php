<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The "Import & export" module is gone, so its permission goes too.
 *
 * ── Why a migration and not a line in the sync command ───────────
 *
 * `ezbizi:sync-permissions` deliberately never deletes — its own docblock says
 * so, and says why: "a role somewhere may reference one, and silently dropping
 * it would quietly widen or narrow access with no trace. Removal is a migration
 * written by a human who has thought about the roles pointing at it." This is
 * that human, and this is that thinking.
 *
 * ── What the row would do if it were left ────────────────────────
 *
 * Nothing, which is the problem. Every consumer of a permission key intersects
 * with the module registry before it does anything — PermissionCatalogue's
 * `heldBy`, `keysDeclaredBy` and `withImpliedView` all drop keys the registry
 * does not declare, and `grantableTreeFor` walks the registry rather than the
 * table. So `data.view` is already ungrantable on the roles screen and already
 * grants nothing to anybody holding it.
 *
 * That is exactly the state the workflows→automations rename called "worse than
 * either keeping or dropping it": a row that survives and silently stops meaning
 * anything. A role still listing "View import & export" among its permissions
 * would be telling an administrator something untrue about what that role does,
 * on the one screen whose whole job is to say what a role does.
 *
 * ── What is deliberately NOT deleted ─────────────────────────────
 *
 * The audit entries. `data.exported`, `data.imported` and `data.inventory_read`
 * are audit ACTION keys, not permission keys, and the endpoints that write them
 * are unchanged and unmoved — only the screens that reach them moved. §10 makes
 * the log append-only regardless; a migration that pruned it would be the one
 * thing the log exists to make impossible.
 *
 * Nor is anything in `platform_module_flags` assumed absent: §13's kill switch
 * writes a row keyed on the module, and one left behind for a module that no
 * longer exists would be a switch with nothing behind it. It is dropped with the
 * rest, for the same reason.
 *
 * ── Reversibility, honestly ──────────────────────────────────────
 *
 * `down()` puts the permission row back so a rollback lands on a schema the old
 * code recognises. It CANNOT put the grants back: which roles held `data.view`
 * is exactly what these rows record, and deleting them destroys it. Recording
 * them somewhere first would mean a table whose only purpose is to survive a
 * rollback nobody expects to perform, for a permission that grants nothing.
 *
 * The cost of that is bounded and worth stating out loud: rolling this back
 * gives an organization the module again with nobody holding its tab except
 * Administrators, who hold the whole catalogue by construction and never had a
 * row here to lose. Somebody would have to re-tick it on the roles that had it.
 */
return new class extends Migration
{
    private const KEY = 'data.view';

    private const MODULE = 'data';

    public function up(): void
    {
        $permission = DB::table('permissions')->where('key', self::KEY)->first();

        if ($permission !== null) {
            // The grants first. A permissions row deleted out from under its
            // pivot rows leaves them pointing at nothing, and whether that
            // errors or is silently tolerated depends on the driver.
            DB::table('permission_role')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
        }

        DB::table('platform_module_flags')->where('module_key', self::MODULE)->delete();
    }

    public function down(): void
    {
        if (DB::table('permissions')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'id'     => (string) Str::uuid(),
            'key'    => self::KEY,
            'module' => self::MODULE,
            'action' => 'view',
            // Composed exactly as PermissionCatalogue::labelFor would from the
            // module definition this is restoring, so a sync afterwards agrees
            // with it instead of disagreeing quietly forever.
            'label'      => 'View import & export',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
