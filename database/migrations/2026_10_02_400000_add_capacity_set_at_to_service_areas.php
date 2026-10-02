<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an area's week was last saved (Mia, 2026-10-02). A closed day has no
 * slot rows, so a week saved closed every day looked exactly like an area
 * nobody had set up, and Availability said "check Workiz" for San Antonio.
 * Areas that already hold slots are stamped now; an all-closed area saved
 * before this has to be saved once more to count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->timestamp('capacity_set_at')->nullable();
        });

        DB::table('service_areas')
            ->whereIn('id', DB::table('service_area_slots')->select('service_area_id'))
            ->update(['capacity_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('service_areas', fn (Blueprint $table) => $table->dropColumn('capacity_set_at'));
    }
};
