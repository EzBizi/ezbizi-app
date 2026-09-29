<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A weekly pattern that takes over from a date (Eve, via Jose, 2026-09-29: "2
 * from Monday to Friday, but she wants that to change automatically starting
 * from October 30, going forward, to be 3 on Monday 2 on Friday").
 *
 * Same shape as `service_area_slots`, plus the date it starts. Openings reads
 * the latest version that has started for each day, so a future week already
 * shows its new numbers; on the day itself scheduling:apply-scheduled-changes
 * copies the version into `service_area_slots` and removes it, so everything
 * else that reads the weekly pattern (the Overview, the pattern check) sees
 * the change without having to know versions exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_area_scheduled_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('max_jobs');
            $table->timestamps();

            $table->unique(['service_area_id', 'starts_on', 'weekday', 'start_time'], 'sa_scheduled_slots_unique');
            $table->index(['service_area_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_scheduled_slots');
    }
};
