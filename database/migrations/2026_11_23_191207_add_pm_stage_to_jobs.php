<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days in the current Project Management stage (Jose, 2026-11-23: "the amount
 * of time a job stays in that status like in unscheduled, or ready to
 * schedule"): the sub-tab a job was last found on, and when it got there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('pm_stage', 40)->nullable();
            $table->timestamp('pm_stage_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['pm_stage', 'pm_stage_at']);
        });
    }
};
