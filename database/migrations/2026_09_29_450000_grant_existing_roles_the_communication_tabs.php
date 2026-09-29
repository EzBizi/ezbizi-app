<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Communication gained tab grants (Jose, 2026-09-29: "we need to add the tab
 * support like all the others"). Every role that can open Communication today
 * gets all five, so nobody loses a pane on deploy; Jose narrows them in Roles.
 * Same shape as 2026_09_29_300000 did for Scheduling's Overbooking tab.
 */
return new class extends Migration
{
    private const MODULE_VIEW = 'phone.view';

    private const TABS = [
        'phone.phone.view',
        'phone.users.view',
        'phone.records.view',
        'phone.reports.view',
        'phone.settings.view',
    ];

    public function up(): void
    {
        $viewId = DB::table('permissions')->where('key', self::MODULE_VIEW)->value('id');

        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        foreach (self::TABS as $key) {
            $permission = app(PermissionCatalogue::class)->materialise($key);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permission->id,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        // Empty on purpose, as in 2026_09_29_300000: rolling back must not undo
        // a role an administrator has since narrowed.
    }
};
