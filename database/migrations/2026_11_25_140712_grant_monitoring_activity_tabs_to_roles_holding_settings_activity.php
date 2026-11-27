<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings › Activity and Settings › API activity moved to Monitoring
 * (Jose, 2026-11-25). Whoever could open them yesterday opens them today:
 *
 *   audit.view                    -> monitoring.activity.view
 *   organizations.api_activity.view -> monitoring.api_activity.view
 *
 * `audit.view` stays in the registry (omitted from the editor), so its rows
 * stay. `organizations.api_activity.view` left the registry, so after its
 * holders are copied its role links and its row go (SyncPermissions never
 * deletes; removal is this migration's job). Administrators bypass the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->copy('audit.view', 'monitoring.activity.view');
        $this->copy('organizations.api_activity.view', 'monitoring.api_activity.view');

        $old = Permission::query()->where('key', 'organizations.api_activity.view')->value('id');

        if ($old !== null) {
            DB::table('permission_role')->where('permission_id', $old)->delete();
            Permission::query()->whereKey($old)->delete();
        }
    }

    private function copy(string $from, string $to): void
    {
        $fromId = Permission::query()->where('key', $from)->value('id');

        if ($fromId === null) {
            return;
        }

        $toId = app(PermissionCatalogue::class)->materialise($to)->id;

        DB::table('permission_role')->insertOrIgnore(
            DB::table('permission_role')
                ->where('permission_id', $fromId)
                ->pluck('role_id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $toId])
                ->all()
        );
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have narrowed a role since.
    }
};
