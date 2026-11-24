<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `project_board.settings.view` (roles audit, 2026-11-24): Project Management ›
 * Settings became its own grant. It was drawn for anybody holding the module
 * and `organizations.edit`, so every role holding both keeps the tab.
 * Administrators bypass the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = Permission::query()->where('key', 'project_board.view')->value('id');
        $editId = Permission::query()->where('key', 'organizations.edit')->value('id');

        if ($viewId === null || $editId === null) {
            return;
        }

        $roles = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id')
            ->intersect(DB::table('permission_role')->where('permission_id', $editId)->pluck('role_id'));

        if ($roles->isEmpty()) {
            return;
        }

        $settings = app(PermissionCatalogue::class)->materialise('project_board.settings.view');

        DB::table('permission_role')->insertOrIgnore(
            $roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $settings->id])->values()->all()
        );
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have narrowed a role since.
    }
};
