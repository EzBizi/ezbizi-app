<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marketing › Ads (ads_manager, Jose 2026-11-09): every role holding
 * ads_performance.view or .manage gets the same on ads_manager, so nobody
 * loses the Meta screen or the switches they had when Google's report went
 * back to Reports. Only these two keys. down() is empty, as the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ads_performance.view' => 'ads_manager.view', 'ads_performance.manage' => 'ads_manager.manage'] as $from => $key) {
            $fromId = Permission::query()->where('key', $from)->value('id');
            if ($fromId === null) {
                continue;
            }
            $permission = app(PermissionCatalogue::class)->materialise($key);
            DB::table('permission_role')->insertOrIgnore(
                DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id')
                    ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void {}
};
