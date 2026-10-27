<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Recommended area" (Jose, 2026-10-27, after Workiz): a centre ZIP and a radius in miles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->string('center_zip', 10)->nullable()->after('color');
            $table->unsignedSmallInteger('radius_miles')->nullable()->after('center_zip');
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn(['center_zip', 'radius_miles']);
        });
    }
};
