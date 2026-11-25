<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Leaderboard (Trey, approved by Jose 2026-11-25) is for the people who
 * take New Client Calls: the roles named Remote Dispatcher, Dispatcher and
 * Dispatch Supervisor get both tabs. An administrator holds every key already
 * (Role::hasPermission); any other role gets it when somebody ticks it in Roles.
 *
 * By name in every organization, on purpose: the Desk's brief named the roles
 * that take New Client Calls, not one tenant. Measured on production
 * 2026-11-25: only Triplex Group has roles by these names.
 */
return new class extends Migration
{
    private const ROLES = ['remote dispatcher', 'dispatcher', 'dispatch supervisor'];

    private const KEYS = ['leaderboard.view', 'leaderboard.mine.view'];

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(TRIM(name))'), self::ROLES)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        // materialise(): on a fresh database the rows do not exist yet.
        foreach (self::KEYS as $key) {
            $permission = app(PermissionCatalogue::class)->materialise($key);
            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void
    {
        // Empty on purpose, as the Scoring tab's grant: by a rollback an
        // administrator may have changed who holds it.
    }
};
