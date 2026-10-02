<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inventory and Fleet gained a General tab - the module's dashboard - and
 * Fleet's register became a Vehicles tab beside it (Mia, 2026-10-02). Every
 * role that could open the module gets them, or the register a role used
 * yesterday would vanish until somebody found the new checkboxes.
 */
return new class extends Migration
{
    /** Module view key => the tab keys every holder of it is given. */
    private const GRANTS = [
        'inventory.view' => ['inventory.general.view'],
        'fleet_management.view' => ['fleet_management.general.view', 'fleet_management.vehicles.view'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $moduleView => $tabs) {
            $viewId = DB::table('permissions')->where('key', $moduleView)->value('id');

            if ($viewId === null) {
                continue;
            }

            $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');

            if ($roleIds->isEmpty()) {
                continue;
            }

            foreach ($tabs as $tab) {
                // materialise(): on a fresh database the row does not exist yet.
                $permission = app(PermissionCatalogue::class)->materialise($tab);

                DB::table('permission_role')->insertOrIgnore(
                    $roleIds->map(fn ($roleId) => [
                        'role_id' => $roleId,
                        'permission_id' => $permission->id,
                    ])->all()
                );
            }
        }
    }

    public function down(): void
    {
        // Empty on purpose, as 2026_10_02_100100's is: rolling the code back
        // leaves these rows inert, and revoking could undo a deliberate grant.
    }
};
