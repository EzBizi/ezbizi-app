<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leads' RingCentral calls source (Jose, 2026-10-15): every role that can open Leads
 * gets `leads.ringcentral.view`, or it would never see a RingCentral call arrive -
 * the silent direction. See 2026_09_23_170000 for the full reasoning.
 *
 * Only this one key, NOT the whole-registry sweep the earlier copies ran: a
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

        $permission = app(PermissionCatalogue::class)->materialise('leads.ringcentral.view');

        $rows = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id')
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])
            ->all();

        DB::table('permission_role')->insertOrIgnore($rows);
    }

    public function down(): void {}
};
