<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles audit (Jose, 2026-11-09): three grants gain a key of their own, and
 * every role keeps what it could do.
 *
 *   invoices.edit          → invoices.settings.view  (the Settings tab was the edit tick)
 *   hr.edit                → company.edit            (Company was edited on HR's tick)
 *   ads_performance.manage → ads_manager.manage      (two names, one authority)
 *
 * Runs before 2026_11_10_034218, which removes ads_performance.manage.
 */
return new class extends Migration
{
    private const COPIES = [
        'invoices.edit' => 'invoices.settings.view',
        'hr.edit' => 'company.edit',
        'ads_performance.manage' => 'ads_manager.manage',
    ];

    public function up(): void
    {
        foreach (self::COPIES as $from => $to) {
            $fromId = DB::table('permissions')->where('key', $from)->value('id');
            $roleIds = $fromId === null ? collect() : DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id');

            if ($roleIds->isEmpty()) {
                continue;
            }

            $permission = app(PermissionCatalogue::class)->materialise($to);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void
    {
        // Empty on purpose, as 2026_11_09_094827: revoking could undo an
        // administrator's own later choice.
    }
};
