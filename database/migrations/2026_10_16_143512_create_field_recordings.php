<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Field safety recordings, ported from A1 Tools (2026-10-16): a technician
 * records audio on a visit; the phone uploads it in one-minute chunks so a
 * crash loses a minute at most. A recording's gaps (a phone call, a pause)
 * are kept on it as a JSON list rather than a third table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('job_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedInteger('duration_s')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->json('events')->nullable();
            // The technician confirmed they told the customer (all-party states).
            $table->boolean('customer_told')->default(false);
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason', 500)->nullable();
            $table->uuid('flagged_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->nullOnDelete();
            $table->foreign('flagged_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'started_at']);
            $table->index(['organization_id', 'started_at']);
        });

        Schema::create('field_recording_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recording_id');
            $table->unsignedInteger('seq');
            $table->dateTime('started_at');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->string('path');
            $table->timestamps();

            $table->foreign('recording_id')->references('id')->on('field_recordings')->cascadeOnDelete();
            $table->unique(['recording_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_recording_chunks');
        Schema::dropIfExists('field_recordings');
    }
};
