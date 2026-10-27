<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jose, 2026-10-27: EzBizi is the master and Workiz a mirror. A plan's type is
 * the EzBizi job's own job_type now; Workiz receives it from the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->renameColumn('workiz_job_type', 'job_type');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->renameColumn('job_type', 'workiz_job_type');
        });
    }
};
