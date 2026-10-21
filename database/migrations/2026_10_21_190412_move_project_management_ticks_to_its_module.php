<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project Management left Jobs for its own module (Jose, 2026-10-21), so every
 * role that held the Jobs tab's tick holds the module's view: nobody loses it.
 */
return new class extends Migration
{
    private const FROM = 'jobs.project_management.view';

    private const TO = 'project_management.view';

    public function up(): void
    {
        $from = DB::table('permissions')->where('key', self::FROM)->value('id');
        if ($from === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $from)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $to = app(PermissionCatalogue::class)->materialise(self::TO);

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $to->id])->all()
        );
    }

    public function down(): void
    {
        // Additive: a role keeps what it was given.
    }
};
