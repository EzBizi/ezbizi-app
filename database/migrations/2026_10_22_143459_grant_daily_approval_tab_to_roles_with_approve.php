<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work Hours gained a Daily approval tab (design 136, 2026-10-22). Every role
 * that may approve a day gets the tab that shows it, so a role ticked for
 * `work_hours.approve` before this tab shipped does not hold a grant with no
 * screen. Same shape as 2026_10_20_143018 (Scheduling › Helpers).
 */
return new class extends Migration
{
    private const APPROVE = 'work_hours.approve';

    private const TAB = 'work_hours.approval.view';

    public function up(): void
    {
        $approveId = DB::table('permissions')->where('key', self::APPROVE)->value('id');

        if ($approveId === null) {
            // Never materialised, so no role can hold it. Nothing to preserve.
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $approveId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise(self::TAB);

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Empty: a role narrowed since is a decision; the key goes inert on rollback.
    }
};
