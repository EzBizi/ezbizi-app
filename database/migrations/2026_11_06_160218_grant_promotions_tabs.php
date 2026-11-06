<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotions (Jose, 2026-11-06): every role that can open Leads gets
 * `leads.promotions.view`, or it would never see a reply arrive - the silent
 * direction (see 2026_10_16_160646). `memberships.promotions.view`, the tab
 * that sends, goes to the roles that may change memberships.
 *
 * Only these two keys, not a registry sweep. down() is empty, as theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leads.view' => 'leads.promotions.view', 'memberships.edit' => 'memberships.promotions.view'] as $from => $key) {
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
