<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Financials gained tabs (Bases, Settings). Every role that could open
 * Financials keeps its bases; Settings is a new grant an administrator gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', 'financials.view')->value('id');
        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('financials.bases.view');
        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Leaves the grants: removing them could take bases away from a role that was given them by hand.
    }
};
