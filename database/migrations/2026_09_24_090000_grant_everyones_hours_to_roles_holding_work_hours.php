<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `hr.hours.all` (2026-09-24): Work Hours without it shows only your own time.
 *
 * Every role that holds Work Hours today sees everybody, so it gets the new
 * grant too — otherwise they are silently narrowed on deploy. Administrators
 * bypass the table and need nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabId = Permission::query()->where('key', 'hr.hours.view')->value('id');

        if ($tabId === null) {
            return;
        }

        $all = app(PermissionCatalogue::class)->materialise('hr.hours.all');

        DB::table('permission_role')->insertOrIgnore(
            DB::table('permission_role')
                ->where('permission_id', $tabId)
                ->pluck('role_id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $all->id])
                ->all()
        );
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have narrowed a role since.
    }
};
