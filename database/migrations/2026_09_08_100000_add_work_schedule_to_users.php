<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's work schedule and their lock-screen exemption (design 2026-09-08 §5.1).
 *
 * JSON, not a table: one scope (this person), no cascade, no query that asks
 * "who set Monday". The shape is validated in code (WorkSchedule::validate).
 * HR data rather than a preference — somebody else sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('work_schedule')->nullable()->after('preferences');
            $table->boolean('lock_screen_exempt')->default(false)->after('work_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['work_schedule', 'lock_screen_exempt']);
        });
    }
};
