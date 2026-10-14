<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board creation moved from tasks.edit to its own tick, tasks.create_boards (Jose, 2026-10-14: he wants to set it in Roles, not lose it). Every role that holds tasks.edit keeps creating boards
 * until somebody unticks it. view_all and manage_members are new powers
 * and are deliberately not granted here.
 */
return new class extends Migration
{
    private const MODULE_VIEW = 'tasks.edit';

    private const TAB = 'tasks.create_boards';

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
