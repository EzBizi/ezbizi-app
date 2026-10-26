<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Everybody on staff may ask for their own paid time off (Jose, 2026-10-26):
 * `work_hours.pto.request` to every role there is today. A role made later
 * gets it when somebody ticks it.
 */
return new class extends Migration
{
    private const KEY = 'work_hours.pto.request';

    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise(self::KEY);

        DB::table('permission_role')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void
    {
        // Grants are not taken back: a role that has since been given the key
        // on purpose cannot be told apart from one this gave it to.
    }
};
