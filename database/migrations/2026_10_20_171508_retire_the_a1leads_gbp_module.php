<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * The A1Leads GBP tab is gone (Jose, 2026-10-20: "let's get rid of the A1Leads
 * GBP tab, since we are now working directly on the customer experience tab"),
 * so its permissions go too - the precedent is the Import & export retirement
 * (2026_08_12_090000): sync-permissions never deletes, so a human does, grants
 * first, so no role keeps a tick that grants nothing and no row points at none.
 *
 * Its tables stay: a1leads_reviews is a frozen copy with its own retention, and
 * automation_events rows still name A1LeadsReviewEvent subjects. The grants
 * are saved to storage/app/backups/a1leads_gbp_grants.json first; down() puts
 * the permission rows and those grants back.
 */
return new class extends Migration
{
    private const MODULE = 'a1leads_gbp';

    public function up(): void
    {
        $ids = DB::table('permissions')->where('key', 'like', self::MODULE.'.%')->pluck('id');

        // Who held which tick, kept before it is deleted, so down() can give
        // the grants back (desk, 2026-10-20).
        $held = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permission_role.permission_id', $ids)->get(['permission_role.role_id', 'permissions.key'])
            ->map(fn ($r) => ['role_id' => $r->role_id, 'key' => $r->key])->all();
        File::ensureDirectoryExists(dirname(self::backup()));
        File::put(self::backup(), json_encode($held, JSON_PRETTY_PRINT));

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('platform_module_flags')->where('module_key', self::MODULE)->delete();
    }

    public function down(): void
    {
        foreach (['view' => 'View A1Leads GBP', 'reply' => 'Reply on A1Leads GBP', 'manage' => 'Manage A1Leads GBP'] as $action => $label) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'key' => self::MODULE.'.'.$action,
                'module' => self::MODULE, 'action' => $action, 'label' => $label,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (File::exists(self::backup()) ? (array) json_decode(File::get(self::backup()), true) : [] as $grant) {
            $permission = DB::table('permissions')->where('key', $grant['key'])->value('id');
            if ($permission !== null && DB::table('roles')->where('id', $grant['role_id'])->exists()) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $grant['role_id'], 'permission_id' => $permission]);
            }
        }
    }

    private static function backup(): string
    {
        return storage_path('app/backups/a1leads_gbp_grants.json');
    }
};
