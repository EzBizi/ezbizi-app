<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the schedule said about this shift (design 2026-09-08 §5.2).
 *
 * `scheduled_end_at` is the effective end after extensions and is what the
 * per-minute close reads. `ended_by` says who closed it: a person, the
 * schedule, or an automation. Additive; `open_slot` is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dateTime('scheduled_end_at')->nullable()->after('clocked_out_at');
            $table->unsignedTinyInteger('extensions')->default(0)->after('scheduled_end_at');
            $table->boolean('outside_schedule')->default(false)->after('extensions');
            $table->string('ended_by', 16)->nullable()->after('outside_schedule');
            $table->index(['scheduled_end_at', 'clocked_out_at'], 'time_clock_shifts_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dropIndex('time_clock_shifts_due_index');
            $table->dropColumn(['scheduled_end_at', 'extensions', 'outside_schedule', 'ended_by']);
        });
    }
};
