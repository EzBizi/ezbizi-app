<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leads' EZ Calls and EZ Texts sources (Jose, 2026-10-16): every role that can open Leads
 * gets `leads.ez_calls.view` and `leads.ez_texts.view`, or it would never see one arrive -
 * the silent direction. See 2026_09_23_170000 for the full reasoning.
 *
 * Only these two keys, NOT the whole-registry sweep the earlier copies ran: a
 * sweep would re-grant every other tab an administrator has narrowed since.
 * down() is empty for the same reason as theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = Permission::query()->where('key', 'leads.view')->value('id');

        if ($viewId === null) {
            return;
        }

        $roles = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');

        foreach (['leads.ez_calls.view', 'leads.ez_texts.view'] as $key) {
            $permission = app(PermissionCatalogue::class)->materialise($key);
            DB::table('permission_role')->insertOrIgnore(
                $roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void {}
};
