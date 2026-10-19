<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Financials gained a Bills tab; every role that can open Financials sees it. */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', 'financials.view')->value('id');
        $roleIds = $viewId === null ? collect() : DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('financials.bills.view');
        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Leaves the grants, as the Bases tab's migration does.
    }
};
