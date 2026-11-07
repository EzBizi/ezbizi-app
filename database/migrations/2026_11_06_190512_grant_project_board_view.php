<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project Management (project_board, Jose 2026-11-06) replaces the two deleted
 * ones, so every role that sees jobs sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $from = DB::table('permissions')->where('key', 'jobs.view')->value('id');
        if ($from === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $from)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $to = app(PermissionCatalogue::class)->materialise('project_board.view');

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $to->id])->all()
        );
    }

    public function down(): void
    {
        // Additive: a role keeps what it was given.
    }
};
