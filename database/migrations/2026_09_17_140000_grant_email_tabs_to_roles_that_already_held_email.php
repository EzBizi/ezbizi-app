<?php

use App\Models\Permission;
use App\Support\Access\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every role holding `email.view` keeps both Email tabs.
 *
 * ── WHY THIS EXISTS WHEN A GENERIC ONE ALREADY DOES ──────────────
 *
 * `2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open`
 * loops the whole registry and would cover Email on its own — but only if
 * Email's `tabs:` declaration is in the SAME release. It reads the registry at
 * the moment it runs, once, and is then marked as run for ever.
 *
 * Measured 2026-09-17: that migration is absent from production's
 * `migrate:status`, so it has not run anywhere yet. The next cut should
 * therefore carry both and the generic one would do the job. "Should" is the
 * word this file exists for: cuts are made by another session at times nobody
 * here controls, and a release carrying the migration WITHOUT Email's
 * declaration marks it run, skips Email for ever, and leaves every role holding
 * `email.view` with two tabs it cannot open — a module that silently empties
 * for everybody, discovered by somebody opening their mail.
 *
 * So this is insurance against an ordering nobody owns, not a second opinion
 * about the mechanism.
 *
 * ── IT IS A NO-OP IN THE ORDINARY CASE ───────────────────────────
 *
 * If the generic migration ran first with Email declared, every row this would
 * write already exists and `insertOrIgnore` writes nothing. If it ran first
 * WITHOUT Email declared, this repairs exactly the gap. If it never ran, this
 * still stands alone. All three orders end in the same place, which is the
 * property that makes it safe to add rather than a duplicate to argue about.
 *
 * Administrators are untouched: `Role::hasPermission` short-circuits on
 * `is_administrator` and they hold no permission rows to widen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewId = Permission::query()->where('key', 'email.view')->value('id');

        if ($viewId === null) {
            return;
        }

        $roleIds = DB::table('permission_role')
            ->where('permission_id', $viewId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $catalogue = app(PermissionCatalogue::class);

        foreach (['email.inbox.view', 'email.settings.view'] as $key) {
            // materialise() rather than a hand-written insert: a permission row
            // carries `module` and `action` beside the key, and it is what lets
            // this run before `ezbizi:sync-permissions` on a fresh database.
            $permission = $catalogue->materialise($key);

            DB::table('permission_role')->insertOrIgnore(
                $roleIds
                    ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])
                    ->all()
            );
        }
    }

    public function down(): void
    {
        /*
         * Empty, for the reason the generic migration gives in full: the
         * reverse of "grant both tabs" is not "revoke both tabs". By rollback
         * time an administrator may have narrowed a role deliberately, and
         * revoking wholesale would overwrite a decision somebody made. The keys
         * stop being declared when the code rolls back and the rows go inert on
         * their own.
         */
    }
};
