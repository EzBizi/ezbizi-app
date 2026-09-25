<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The most jobs a whole franchise takes on a weekday, whatever its areas have
 * left. Jose, 2026-09-25, on DMV: its three areas add up to 19 a day, but the
 * technicians are shared, so "let's say that i put the DMV Limit at 15 per
 * day ... when im at 15 bookings already it should stop offering said day".
 *
 * A ROW IS A LIMIT; NO ROW IS NO LIMIT. Never a zero: a franchise that works
 * no jobs that day is closed by its areas, not by a limit of nothing — the
 * same rule `service_area_slots` keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_daily_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('franchise_id');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedInteger('max_jobs');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->cascadeOnDelete();
            $table->unique(['franchise_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_daily_limits');
    }
};
