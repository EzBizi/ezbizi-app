<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles audit (Jose, 2026-11-09): keys the registry no longer declares, or
 * that gated nothing, come off every role. They granted nothing, but
 * PermissionCatalogue::preservedFor carried them through each save, so a key
 * declared again later would have silently re-granted itself.
 *
 * A dump of permissions and permission_role was taken before this deployed.
 */
return new class extends Migration
{
    private const KEYS = [
        'scheduling.overbooking.view',
        'marketing.posts.view',
        'leads.create', 'leads.delete', 'leads.export',
        'reviews.create', 'reviews.delete',
        'business_profile.create', 'business_profile.delete',
        // Copied onto ads_manager.manage by 2026_11_10_034217.
        'ads_performance.manage',
    ];

    public function up(): void
    {
        // permission_role rows go with them (cascadeOnDelete).
        DB::table('permissions')
            ->whereIn('key', self::KEYS)
            ->orWhere('key', 'like', 'sales.%')
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: none of these keys is declared any more.
    }
};
