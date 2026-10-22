<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email → Google Workspace is its own tab (2026-10-22). Its API was reached
 * through Email settings until now, so every role holding the Settings tab
 * gets this one too: nobody loses what they could already do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settingsId = Permission::query()->where('key', 'email.settings.view')->value('id');

        if ($settingsId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $settingsId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('email.workspace.view');

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Empty: a role narrowed since is a decision; the key goes inert on rollback.
    }
};
