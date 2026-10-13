<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports gained a Scoring tab (Jose, 2026-10-13): each dispatcher as a card,
 * ranked by AI's call score. Jose, relayed by Grok AI the same day: "right now
 * only administrators and lead manager". An administrator holds every key
 * already (Role::hasPermission), so the one grant to make is the roles named
 * Lead Manager; no other role gets it until somebody ticks it in Roles.
 */
return new class extends Migration
{
    private const TAB = 'reports.scoring.view';

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['lead manager'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        // materialise() rather than a plain select: on a fresh database the row
        // does not exist yet (see 2026_10_13 Re-Scheduling's grant).
        $permission = app(PermissionCatalogue::class)->materialise(self::TAB);

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => [
                'role_id' => $roleId,
                'permission_id' => $permission->id,
            ])->all()
        );
    }

    public function down(): void
    {
        // Empty on purpose, as in the Re-Scheduling grant: by a rollback an
        // administrator may have changed who holds it, and revoking wholesale
        // would undo that. The registry dropping the key makes the rows inert.
    }
};
