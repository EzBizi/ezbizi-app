<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A camera can now come through a site's bridge instead of a public URL
 * (camera bridge, 2026-10-20). Every existing row is `source = url` and keeps
 * working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_cameras', function (Blueprint $table) {
            $table->foreignUuid('site_id')->nullable()->after('organization_id')
                ->constrained('camera_sites')->cascadeOnDelete();
            $table->string('source', 16)->default('url')->after('site_id'); // url | bridge
            // Which recorder channel or camera address the bridge maps this to.
            $table->string('channel', 64)->nullable()->after('url');
            // What this camera supports through its bridge: ["live"], later recordings, alerts.
            $table->json('capabilities')->nullable()->after('channel');
            $table->string('bridge_status', 24)->nullable()->after('capabilities'); // ok | unreachable | auth_failed
            $table->string('bridge_reason', 255)->nullable()->after('bridge_status');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_cameras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['source', 'channel', 'capabilities', 'bridge_status', 'bridge_reason']);
        });
    }
};
