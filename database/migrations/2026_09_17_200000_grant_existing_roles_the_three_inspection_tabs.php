<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses an Inspection tab they had this morning.
 *
 * ── Why this exists when 090000 already does it ─────────────────
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * walks the ModuleRegistry and grants every declared tab to every role holding
 * that module's view. It is the right shape and it covers Inspection — PROVIDED
 * it runs after this module's `tabs:` declaration is on the branch being
 * deployed. Both are pending as this is written, so on the ordinary path this
 * one inserts nothing.
 *
 * It exists for the path that is not ordinary. 090000 is derived from the
 * registry AT THE MOMENT IT EXECUTES: run it against a tree where Inspection
 * has no tabs and it grants nothing for Inspection, marks itself run, and never
 * looks again. My declaration landing afterwards would then leave every
 * existing role holding none of the three keys — and the client filters on
 * exactly those, so every Inspection tab disappears for everybody, with no
 * error anywhere and nothing in the release notes.
 *
 * That is not hypothetical here: this module's declaration was blocked from
 * main for hours by an unrelated file, while 090000 sat Pending and deployable.
 *
 * ── Why redundant is the right answer rather than clever ────────
 *
 * `insertOrIgnore` over the same rows, so running both is idempotent and the
 * order does not matter. If 090000 runs first and covers Inspection, this
 * inserts nothing and costs one query per tab. The Web module reached the same
 * conclusion for the same reason on the same day, and their sentence for it is
 * the one worth keeping: redundant and cheap beats clever and load-bearing.
 *
 * NOT registry-derived, deliberately. The three keys are written out, so this
 * grants what it says even if the declaration is missing, renamed, or the
 * registry cannot be booted. A copy that reads the registry would inherit the
 * exact failure it is insurance against.
 */
return new class extends Migration
{
    /** The tab keys as declared in ModuleServiceProvider's inspection block. */
    private const TABS = [
        'inspection.new.view',
        'inspection.reports.view',
        'inspection.builder.view',
    ];

    public function up(): void
    {
        $viewId = Permission::query()->where('key', 'inspection.view')->value('id');

        if ($viewId === null) {
            // Inspection's own view has never been materialised, so no role can
            // be holding it and there is nothing to widen.
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $catalogue = app(PermissionCatalogue::class);

        foreach (self::TABS as $key) {
            $permission = $catalogue->materialise($key);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permission->id,
                ])->all(),
            );
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would revoke tabs from roles that may have been granted them
     * by 090000, by hand in the roles editor, or by this — and the three are
     * indistinguishable in the pivot. Taking a permission away because a
     * migration was reversed is a worse outcome than leaving a grant somebody
     * can untick.
     */
    public function down(): void {}
};
