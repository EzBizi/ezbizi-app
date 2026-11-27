<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Communication gained a Chat tab (Jose, 2026-11-25). Every role that can open
 * Communication gets it, the way 2026_11_16_120000 granted Comments -
 * otherwise only administrators see it until somebody finds the checkbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', 'phone.view')->value('id');

        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('phone.chat.view');

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Deliberately empty, for the reason 2026_09_29_300000 gives.
    }
};
