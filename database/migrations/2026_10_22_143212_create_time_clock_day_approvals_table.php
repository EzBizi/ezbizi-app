<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Hours › Daily approval (Shoval via Jose, 2026-10-22): one row per
 * person per day, waiting or approved. The day is the ORGANIZATION'S
 * calendar day, as time_clock_days uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_clock_day_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 16)->default('waiting');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['organization_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_clock_day_approvals');
    }
};
