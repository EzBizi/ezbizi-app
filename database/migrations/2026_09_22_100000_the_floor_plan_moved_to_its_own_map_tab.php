<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The floor plan moved from General to a tab of its own, so the grant follows
 * it (Jose, 2026-09-22).
 *
 * General became the ownership chart — the same people and machines as a
 * table — and the map it used to hold is now `Map`. Anybody holding
 * `monitoring.general.view` could open the floor plan yesterday, so they get
 * `monitoring.map.view` today. Without this they keep a tab whose contents
 * changed and lose the one they were actually using, silently, with nothing
 * logged: the direction the whole tab mechanism exists to prevent.
 *
 * ── Why they keep `general.view` as well ─────────────────────────
 *
 * The chart is new, but it is built from what those roles could already
 * reach: who is signed in, on which machine, seen when. Its two columns that
 * are NOT Monitoring's own — the person's work schedule, which is HR's — are
 * withheld per column by the endpoint rather than by this key, so leaving
 * `general.view` in place widens nothing.
 *
 * ── Hard-coded, not derived ──────────────────────────────────────
 *
 * Same reasoning as 2026_09_17_213000: the registry-derived backfill reads at
 * EXECUTION and has long since run, and a copy that re-derives inherits the
 * failure it insures against. Two keys is a cheap list to write out.
 *
 * Administrators are untouched: `Role::isAdministrator()` bypasses the
 * permission table, so they hold no rows to add to.
 */
return new class extends Migration
{
    private const HELD = 'monitoring.general.view';

    private const GRANTED = 'monitoring.map.view';

    public function up(): void
    {
        $heldId = DB::table('permissions')->where('key', self::HELD)->value('id');

        if ($heldId === null) {
            // Never materialised, so nobody holds it and nothing moved for them.
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $heldId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        // materialise() rather than a select: on a fresh database the row is
        // written by `ezbizi:sync-permissions` at deploy, and a migration that
        // assumes that step ran first works on the box and nowhere else.
        $mapId = app(PermissionCatalogue::class)->materialise(self::GRANTED)->id;

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => [
                'role_id' => $roleId,
                'permission_id' => $mapId,
            ])->all()
        );
    }

    public function down(): void
    {
        /*
         * Deliberately empty. The reverse of "the tab moved, so the grant
         * moved with it" is not "take the grant away": by the time anybody
         * rolls back, an administrator may have narrowed a role on purpose.
         * PermissionCatalogue::withImpliedView drops keys the registry no
         * longer declares, so these rows go inert on their own.
         */
    }
};
