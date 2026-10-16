<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `leads.reclassify` and `leads.dispatcher` (A1 Leads takeover, 2026-10-16):
 * new grants for what `leads.edit` holders could do the day they shipped, so
 * every role holding that keeps them. Administrators bypass the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $editId = Permission::query()->where('key', 'leads.edit')->value('id');

        if ($editId === null) {
            return;
        }

        $roles = DB::table('permission_role')->where('permission_id', $editId)->pluck('role_id');

        foreach (['leads.reclassify', 'leads.dispatcher'] as $key) {
            $permission = app(PermissionCatalogue::class)->materialise($key);
            DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have narrowed a role since.
    }
};
