<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_clock_correction_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Who is asking, and the shift of theirs it is about.
            $table->uuid('user_id');
            $table->uuid('shift_id');

            // What the shift said when they asked, and what they say it should say.
            $table->timestamp('was_in_at');
            $table->timestamp('was_out_at')->nullable();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->text('reason');

            // 'pending', 'approved', 'denied'.
            $table->string('status', 20)->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('shift_id')->references('id')->on('time_clock_shifts')->cascadeOnDelete();

            $table->index(['organization_id', 'status', 'created_at'], 'tccr_queue');
            $table->index(['organization_id', 'user_id', 'created_at'], 'tccr_mine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_clock_correction_requests');
    }
};
