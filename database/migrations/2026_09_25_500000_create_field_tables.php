<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Field (docs/superpowers/specs/2026-09-25-field-module-design.md).
 *
 * Four tables: the trail, the evaluator's per-person state, the alerts a
 * manager acknowledges, and geocoded addresses. Coordinates are
 * decimal(10,7), the same as workiz_jobs, so a point read from either
 * compares without rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            // The phone's instant, UTC. created_at is when it reached us, which
            // after a tunnel or a dead zone can be minutes later.
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'recorded_at']);
            $table->index(['organization_id', 'recorded_at']);
        });

        Schema::create('field_presences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id')->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->dateTime('last_seen_at');
            // Where they last stood still, and since when. Moving further than
            // the stop radius from here resets both.
            $table->decimal('anchor_latitude', 10, 7);
            $table->decimal('anchor_longitude', 10, 7);
            $table->dateTime('anchored_at');
            $table->uuid('at_job_id')->nullable();
            $table->boolean('stopped_alerted')->default(false);
            $table->boolean('signal_lost_alerted')->default(false);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('at_job_id')->references('id')->on('jobs')->nullOnDelete();
            $table->index('organization_id');
        });

        Schema::create('field_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('kind', 20);
            $table->uuid('job_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->nullOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('field_geocodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->char('address_hash', 64);
            $table->string('address', 500);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'address_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_geocodes');
        Schema::dropIfExists('field_alerts');
        Schema::dropIfExists('field_presences');
        Schema::dropIfExists('field_locations');
    }
};
