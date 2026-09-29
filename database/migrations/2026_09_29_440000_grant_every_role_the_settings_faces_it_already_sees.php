<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Settings destination's tabs became grants (2026-09-29), and nobody loses
 * one they had this morning.
 *
 * Organization and Settings were drawn for every signed-in person, so every
 * role gets both. Integrations was drawn for `marketing.view`, so exactly those
 * roles get it. `organizations.view` goes beside them because that is what
 * PermissionCatalogue::withImpliedView would add on the role's next save.
 *
 * Administrators are skipped: they hold no rows and pass every check.
 * DB::table rather than Role, because the tenant scope would limit this to
 * whichever organization happened to be current — none, during a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogue = app(PermissionCatalogue::class);

        $roleIds = DB::table('roles')->where('is_administrator', false)->pluck('id');

        $marketingId = DB::table('permissions')->where('key', 'marketing.view')->value('id');

        $marketingRoles = $marketingId === null
            ? collect()
            : DB::table('permission_role')->where('permission_id', $marketingId)->pluck('role_id');

        $grants = [
            'organizations.view'                   => $roleIds,
            'organizations.organization.view'      => $roleIds,
            'organizations.settings.view'          => $roleIds,
            'organizations.integrations.view'      => $marketingRoles->intersect($roleIds),
        ];

        foreach ($grants as $key => $roles) {
            if ($roles->isEmpty()) {
                continue;
            }

            $permissionId = $catalogue->materialise($key)->id;

            foreach ($roles->chunk(500) as $chunk) {
                DB::table('permission_role')->insertOrIgnore(
                    $chunk->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all()
                );
            }
        }
    }

    public function down(): void
    {
        // Empty on purpose, as in 2026_09_17_090000: by rollback time an
        // administrator may have narrowed a role deliberately.
    }
};
