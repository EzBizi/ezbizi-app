<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The A1Leads GBP tab is gone (Jose, 2026-10-20: "let's get rid of the A1Leads
 * GBP tab, since we are now working directly on the customer experience tab"),
 * so its permissions go too - the precedent is the Import & export retirement
 * (2026_08_12_090000): sync-permissions never deletes, so a human does, grants
 * first, so no role keeps a tick that grants nothing and no row points at none.
 *
 * Its tables stay: a1leads_reviews is a frozen copy with its own retention, and
 * automation_events rows still name A1LeadsReviewEvent subjects. down() puts
 * the permission rows back but cannot put back who held them.
 */
return new class extends Migration
{
    private const MODULE = 'a1leads_gbp';

    public function up(): void
    {
        $ids = DB::table('permissions')->where('key', 'like', self::MODULE.'.%')->pluck('id');

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
    }
};
