<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The most jobs one service area takes on a weekday, whatever its time slots
 * add up to. Jose, 2026-09-28: "we have the daily limit for franchise, but we
 * do not have daily limit for service area" — A1 Tools' area day caps
 * (Connecticut Mon 15, Fri 8; Maryland Fri 6). It only ever LOWERS a day.
 *
 * A ROW IS A LIMIT; NO ROW IS NO LIMIT. Never a zero: a day the area does not
 * work is closed by having no time slots, not by a limit of nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_area_daily_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('service_area_id');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedInteger('max_jobs');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('service_area_id')->references('id')->on('service_areas')->cascadeOnDelete();
            $table->unique(['service_area_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_daily_limits');
    }
};
