<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helper scheduling, brought over from A1 Tools (A1 Leads takeover, Jose
 * 2026-10-16 / 10-20 "go ahead"): a franchise's helpers, booked to a
 * technician for a whole day or a half.
 *
 * Helpers are retired, never deleted, so last month's bookings keep a name.
 * A booking's technician is a user; who made it is recorded separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_helpers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('franchise_id');
            $table->string('name', 120);
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['franchise_id', 'is_active']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->cascadeOnDelete();
        });

        Schema::create('scheduling_helper_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('franchise_id');
            $table->uuid('helper_id');
            $table->date('date');
            // full | am | pm. A full day collides with either half.
            $table->string('slot', 4);
            $table->uuid('technician_id');
            $table->string('job_number', 60)->nullable();
            $table->string('note', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            // The hard stop for the same half; full-vs-half is checked under
            // a lock in HelperBookings.
            $table->unique(['helper_id', 'date', 'slot']);
            $table->index(['franchise_id', 'date']);
            $table->index(['technician_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->cascadeOnDelete();
            $table->foreign('helper_id')->references('id')->on('scheduling_helpers')->cascadeOnDelete();
            $table->foreign('technician_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_helper_bookings');
        Schema::dropIfExists('scheduling_helpers');
    }
};
