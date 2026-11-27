<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marketing gained a Comments tab (Jose, 2026-11-16). Every role that can open
 * Marketing gets it, the way 2026_09_29_310000 granted Posts and Accounts -
 * otherwise only administrators see it until somebody finds the checkbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', 'marketing.view')->value('id');

        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('marketing.comments.view');

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Deliberately empty, for the reason 2026_09_29_300000 gives.
    }
};
