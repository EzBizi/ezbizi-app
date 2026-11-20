<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management parity with the Ohio/Illinois Monday boards (desk,
 * 2026-11-20): Job Cost, and Production job, which adds 10% of the job's
 * amount to its Total Cost (Monday's formula).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('job_cost_minor')->nullable();
            $table->boolean('production_job')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['job_cost_minor', 'production_job']);
        });
    }
};
