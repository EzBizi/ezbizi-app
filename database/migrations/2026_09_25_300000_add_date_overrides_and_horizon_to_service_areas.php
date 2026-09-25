<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a weekly pattern cannot say — "closed on the 18th" and "full
 * until the 7th" — which are what A1 Tools' Availability is run on day to day.
 *
 * Jose, 2026-09-25: "I want to make sure we are getting the availability done
 * already so we can finally drop A1Tools". A1's per-date table holds 612 rows
 * and is how Cleveland and Cincinnati are run; its horizon is how Connecticut
 * said "no bookings before 7 September" (gap spec 3.4 and 3.5).
 *
 * ── A separate table, so the weekly pattern keeps its grain ─────
 *
 * `service_area_slots` stays one row per (area, weekday, window). Reports
 * derives "open days a week" from exactly that grain and has a test that reds
 * if it gains a date — so the dates live here instead, and that column keeps
 * meaning the usual week.
 *
 * A date with rows here uses THEM instead of the weekday pattern. A row with
 * no window is the whole day closed. A window row always carries a number
 * above zero — closing is the no-window row, never a zero, the module's rule
 * since the first migration.
 *
 * `available_from` on the area: every day before it is CLOSED, not full. NULL
 * is no floor. It only ever removes days; a date in the past changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_area_date_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('service_area_id');
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('max_jobs')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('service_area_id')->references('id')->on('service_areas')->cascadeOnDelete();
            $table->index(['service_area_id', 'date']);
        });

        Schema::table('service_areas', function (Blueprint $table) {
            $table->date('available_from')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn('available_from');
        });

        Schema::dropIfExists('service_area_date_overrides');
    }
};
