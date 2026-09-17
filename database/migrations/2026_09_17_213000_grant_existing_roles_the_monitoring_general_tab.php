<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses the Monitoring tab they could open this morning.
 *
 * Monitoring declares four tabs and exactly ONE of them mints a new key:
 *
 *   general    MINTS `monitoring.general.view`  <- this file
 *   specs      names `monitoring.specs.view`         already a grant
 *   cameras    names `monitoring.cameras.view`       already a grant
 *   desktop    names `monitoring.screenshots.view`   already a grant
 *
 * ── Why only one key is granted here ─────────────────────────────
 *
 * The other three were separate grants before tabs existed, so a role either
 * held them this morning or did not. Granting them would not be preservation,
 * it would be handing every role that could see the device roster the right to
 * look at colleagues' screens and cameras — the exact widening the
 * registry-derived migration guards with `namesExistingAction()`. The floor-plan
 * map is the only tab that has ridden on plain `monitoring.view`, so it is the
 * only one whose key anybody could lose.
 *
 * ── Why this is hard-coded rather than derived from the registry ──
 *
 * 2026_09_17_090000 does the same job by reading the registry AT EXECUTION, and
 * it covers a module whose declaration is in the tree when it runs. It has
 * already run against production, so it will never look again — and a copy of
 * it that re-derives would inherit exactly that: run before this declaration
 * lands and it grants nothing, marks itself done, and every role keeps
 * `monitoring.view` while the client filters the tab strip on keys nobody
 * holds. Every Monitoring tab disappears at once, silently, in the direction
 * the whole mechanism exists to prevent.
 *
 * One hard-coded key cannot fail that way. It is also the smaller risk here
 * than anywhere else in the sweep, because the list is one item long.
 *
 * Administrators are untouched and need to be: `Role::isAdministrator()`
 * bypasses the permission table, so they hold no rows to add to.
 */
return new class extends Migration
{
    private const MODULE_VIEW = 'monitoring.view';

    private const TAB = 'monitoring.general.view';

    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', self::MODULE_VIEW)->value('id');

        if ($viewId === null) {
            // Never materialised, so no role can hold it. Nothing to preserve.
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        // materialise() rather than a plain select: on a fresh database the row
        // does not exist yet, and a migration that depends on the deploy having
        // run `ezbizi:sync-permissions` first works on the box and nowhere else.
        $permission = app(PermissionCatalogue::class)->materialise(self::TAB);

        // insertOrIgnore because a role may already hold it — this running
        // twice, or somebody granting it between the deploy and the migrate.
        // The pivot carries no timestamps.
        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => [
                'role_id' => $roleId,
                'permission_id' => $permission->id,
            ])->all()
        );
    }

    public function down(): void
    {
        /*
         * Deliberately empty, and honest rather than lazy. The reverse of
         * "grant the tab everybody already had" is not "revoke it": by the time
         * anybody rolls back, an administrator may have narrowed a role on
         * purpose, and revoking wholesale would overwrite that decision.
         * PermissionCatalogue::withImpliedView drops keys the registry no
         * longer declares, so the rows go inert on their own when the code
         * rolls back.
         */
    }
};
