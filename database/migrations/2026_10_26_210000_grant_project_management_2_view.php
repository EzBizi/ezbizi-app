<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project Management - 2 is the rebuild of Project Management beside it
 * (Jose, 2026-10-26), so every role that sees the first sees the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        $from = DB::table('permissions')->where('key', 'project_management.view')->value('id');
        if ($from === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $from)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $to = app(PermissionCatalogue::class)->materialise('project_management_2.view');

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $to->id])->all()
        );
    }

    public function down(): void
    {
        // Additive: a role keeps what it was given.
    }
};
