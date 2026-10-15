<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `leads.manage` (2026-10-15): the Leads Manage pane, where sources are
 * connected, became its own grant. Connecting was `leads.edit` until today, so
 * every role holding that keeps the pane. Administrators bypass the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $editId = Permission::query()->where('key', 'leads.edit')->value('id');

        if ($editId === null) {
            return;
        }

        $manage = app(PermissionCatalogue::class)->materialise('leads.manage');

        DB::table('permission_role')->insertOrIgnore(
            DB::table('permission_role')
                ->where('permission_id', $editId)
                ->pluck('role_id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $manage->id])
                ->all()
        );
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have narrowed a role since.
    }
};
