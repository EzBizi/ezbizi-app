<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Field: what the tracked phone says about itself with each upload, for the
 * technician bar on the map (Jose, 2026-09-30). Latest value only, on the
 * presence row: nobody needs a battery history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_presences', function (Blueprint $table) {
            $table->unsignedTinyInteger('battery_pct')->nullable()->after('accuracy_m');
            $table->boolean('charging')->nullable()->after('battery_pct');
            $table->string('platform', 16)->nullable()->after('charging');
            $table->string('os_version', 64)->nullable()->after('platform');
            $table->string('device_model', 100)->nullable()->after('os_version');
            $table->string('app_version', 32)->nullable()->after('device_model');
        });
    }

    public function down(): void
    {
        Schema::table('field_presences', function (Blueprint $table) {
            $table->dropColumn(['battery_pct', 'charging', 'platform', 'os_version', 'device_model', 'app_version']);
        });
    }
};
