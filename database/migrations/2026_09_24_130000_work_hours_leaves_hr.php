<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work Hours leaves Human Resources (Jose, 2026-09-24): every role keeps
 * exactly what it could do yesterday, under the new module's keys.
 *
 * ── Hard-coded, not derived ──
 *
 * The map is written out rather than read from the registry, because a
 * migration that re-derives inherits whatever the registry says on the day it
 * RUNS (Inspection, 2026-09-17). These keys are the ones this change moved,
 * and they do not change if the registry does later.
 *
 * ── Why organizations.edit gets the Settings tab ──
 *
 * The Clock in and paid-time-off settings are edited under
 * `organizations.edit` (SettingDefinition::permissionRequired). Whoever could
 * change them in Settings could already reach them; the tab is the same
 * rows in a second place, not a new authority.
 *
 * The old `hr.hours.*` and `hr.pto.view` rows are left where they are. They
 * are declared nowhere now, so no editor draws them and no gate reads them;
 * deleting them would make `down()` unable to say what anybody had.
 */
return new class extends Migration
{
    private const MAP = [
        'hr.hours.view' => ['work_hours.view', 'work_hours.general.view'],
        'hr.hours.all' => ['work_hours.all'],
        'hr.hours.correct' => ['work_hours.manage'],
        'hr.pto.view' => ['work_hours.view', 'work_hours.pto.view'],
        'organizations.edit' => ['work_hours.view', 'work_hours.settings.view'],
    ];

    public function up(): void
    {
        $catalogue = app(PermissionCatalogue::class);

        foreach (self::MAP as $from => $to) {
            $fromId = DB::table('permissions')->where('key', $from)->value('id');

            if ($fromId === null) {
                continue;
            }

            $roles = DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id');

            foreach ($to as $key) {
                $permission = $catalogue->materialise($key);

                DB::table('permission_role')->insertOrIgnore(
                    $roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
                );
            }
        }
    }

    public function down(): void
    {
        // Empty on purpose: an administrator may have granted or narrowed the
        // new keys since, and the old rows were never removed.
    }
};
