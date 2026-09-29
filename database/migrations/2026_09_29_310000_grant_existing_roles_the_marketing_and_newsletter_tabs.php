<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Social Media Management and Newsletter gained grantable tabs (Jose,
 * 2026-09-29: tabs should be grantable "like all the others"). Every role that
 * could open each one keeps every pane it had this morning — otherwise the
 * tabs exist and nobody but an administrator sees them until somebody finds the
 * new checkboxes. Same shape as 2026_09_29_300000 for Scheduling.
 */
return new class extends Migration
{
    /** @var array<string, string[]> module view => the tabs it now grants */
    private const TABS = [
        'marketing.view' => ['marketing.posts.view', 'marketing.accounts.view'],
        'newsletter.view' => [
            'newsletter.dashboard.view',
            'newsletter.composer.view',
            'newsletter.settings.view',
        ],
    ];

    public function up(): void
    {
        foreach (self::TABS as $moduleView => $tabs) {
            $viewId = DB::table('permissions')->where('key', $moduleView)->value('id');

            if ($viewId === null) {
                // Never materialised, so no role can hold it. Nothing to preserve.
                continue;
            }

            $roleIds = DB::table('permission_role')
                ->where('permission_id', $viewId)
                ->pluck('role_id');

            if ($roleIds->isEmpty()) {
                continue;
            }

            foreach ($tabs as $tab) {
                // materialise(), not a select: on a fresh database the row does
                // not exist until `ezbizi:sync-permissions` runs, and a migration
                // that depends on that order works on the box and nowhere else.
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
        /*
         * Deliberately empty, for the reason 2026_09_29_300000 gives: by the
         * time anybody rolls back, an administrator may have narrowed a role on
         * purpose, and revoking wholesale would overwrite that. withImpliedView
         * drops keys the registry no longer declares, so the rows go inert.
         */
    }
};
