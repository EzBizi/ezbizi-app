<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service areas, their zip codes, and their capacity per slot per weekday
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md).
 *
 * A slot row is per (area, weekday), which is why capacity has no table of its
 * own — the slot already knows its area and its day, so max_jobs sits on it.
 *
 * `franchise_id` is repeated on the zip table on purpose. The uniqueness that
 * matters is "one zip per franchise", and a unique index cannot reach through a
 * join to service_areas to get it. The alternative is a check nobody remembers,
 * which is what §2 of the spec exists to refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->timestamps();

            $table->unique(['franchise_id', 'name']);
        });

        Schema::create('service_area_zips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            $table->string('zip', 16);
            $table->timestamps();

            // The whole point. One zip, one area, per franchise.
            $table->unique(['franchise_id', 'zip']);
        });

        Schema::create('service_area_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday, matching Carbon's dayOfWeek so no translation is needed
            // at either end.
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('max_jobs');
            $table->timestamps();

            // One slot per area per day per start. Two slots opening at the same
            // minute in the same place is a duplicate, not a configuration.
            $table->unique(['service_area_id', 'weekday', 'start_time']);
            $table->index(['service_area_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_slots');
        Schema::dropIfExists('service_area_zips');
        Schema::dropIfExists('service_areas');
    }
};
