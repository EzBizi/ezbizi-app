<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling counts each area's jobs in a date range (Openings::areaRows); on
 * service_area_id alone that read scanned ~750 rows per area, 92 times per
 * Overbooking load on prod (2026-11-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['service_area_id', 'scheduled_for'], 'jobs_area_scheduled_index');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_area_scheduled_index');
        });
    }
};
