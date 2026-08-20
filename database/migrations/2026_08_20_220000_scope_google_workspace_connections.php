<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Workspace belongs to the place it was connected, not the whole tenant.
 *
 * One row per organization meant A-1 Chimney's domain appeared on Premier
 * Garage Repair the moment anybody opened Email. Each company (and the
 * organization itself) now has its own row. Domain stays unique across the
 * platform, so two tenants still cannot claim the same Google Workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->string('scope_type', 32)->default('organization')->after('organization_id');
            $table->uuid('scope_id')->nullable()->after('scope_type');
        });

        foreach (DB::table('google_workspace_connections')->orderBy('id')->get() as $row) {
            DB::table('google_workspace_connections')->where('id', $row->id)->update([
                'scope_type' => 'organization',
                'scope_id'   => $row->organization_id,
            ]);
        }

        Schema::table('google_workspace_connections', function (Blueprint $table) {
            // The unique index is the one the FK sits on. MySQL will not drop
            // it while the constraint exists, so the FK comes off first and
            // goes back on the same column after the new unique is in place.
            $table->dropForeign(['organization_id']);
            $table->dropUnique(['organization_id']);
            $table->unique(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('google_workspace_sign_ins', function (Blueprint $table) {
            $table->string('scope_type', 32)->nullable()->after('user_id');
            $table->uuid('scope_id')->nullable()->after('scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('google_workspace_sign_ins', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'scope_id']);
        });

        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'scope_type', 'scope_id']);
        });

        Schema::table('google_workspace_connections', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'scope_id']);
            $table->unique('organization_id');
        });
    }
};
