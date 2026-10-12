<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Social Media Management's Posts tab became three - Dashboard, Compose,
 * Calendar (Jose, 2026-10-12, UI queue 76). Every role that held Posts gets all
 * three, so nobody loses a pane on the deploy.
 *
 * The old `marketing.posts.view` rows are left, not deleted: the registry no
 * longer declares the key, so withImpliedView drops it and it cannot be ticked
 * - the rows are inert, and keeping them is what makes rolling the code back
 * give Posts back to the same roles. down() is empty for 2026_09_29_300000's
 * reason.
 */
return new class extends Migration
{
    private const TABS = ['marketing.dashboard.view', 'marketing.compose.view', 'marketing.calendar.view'];

    public function up(): void
    {
        $postsId = DB::table('permissions')->where('key', 'marketing.posts.view')->value('id');

        if ($postsId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $postsId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        foreach (self::TABS as $tab) {
            $permission = app(PermissionCatalogue::class)->materialise($tab);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void
    {
        // Deliberately empty, for the reason 2026_09_29_300000 gives.
    }
};
