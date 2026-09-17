<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody loses a tab they had this morning — the Web Management half.
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * did this for every module declaring tabs at the time it ran, and says in its
 * own docblock that a module declaring tabs AFTER that day must re-run the
 * logic in its own migration rather than expect the earlier one to notice.
 * Automations was in that position at 14:00 and copied it; Web Management
 * declared `seo` and `widgets` later still, so this is the third copy and it is
 * the same shape on purpose.
 *
 * WITHOUT THIS, EVERY EXISTING ROLE KEEPS `web_management.view` AND LOSES BOTH
 * TABS on the next deploy. Both, meaning the whole module: SEO and Widgets are
 * all this screen has, so the pane would draw the sentence it keeps for a role
 * holding neither — accurate, and not what anybody asked for.
 *
 * Scoped to this module deliberately rather than copying the registry sweep: a
 * second sweep over every module would re-grant tabs an administrator may have
 * narrowed on purpose in the hours since the first one ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogue = app(PermissionCatalogue::class);

        $viewId = Permission::query()->where('key', 'web_management.view')->value('id');

        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')->where('permission_id', $viewId)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        foreach (['web_management.seo.view', 'web_management.widgets.view'] as $key) {
            // materialise() rather than a hand-written insert: a permission row
            // carries `module` and `action` beside the key, and this has to work
            // on a fresh database as well as on the box, where
            // ezbizi:sync-permissions runs as a later deploy step.
            $permission = $catalogue->materialise($key);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds->map(fn ($roleId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permission->id,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        /*
         * Empty for the same reason the first two are: the reverse of "grant
         * every tab" is not "revoke every tab". By the time anybody rolls back,
         * an administrator may have narrowed a role deliberately, and revoking
         * wholesale would overwrite that. The keys stop being declared when the
         * code rolls back and the rows go inert on their own.
         */
    }
};
