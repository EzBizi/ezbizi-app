<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-11-27: a reminder is claimed per scheduled date, so a
 * rescheduled job is reminded for its new date; a failed send is kept with
 * its failure count and retried with backoff, not re-noted every sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_board_messages', function (Blueprint $table) {
            $table->string('for_date', 10)->default('')->after('kind');
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->unique(['job_id', 'kind', 'for_date'], 'project_board_messages_job_kind_date_unique');
        });
        Schema::table('project_board_messages', function (Blueprint $table) {
            $table->dropUnique(['job_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('project_board_messages', function (Blueprint $table) {
            $table->dropUnique('project_board_messages_job_kind_date_unique');
            $table->dropColumn(['for_date', 'failed_at', 'failures']);
            $table->unique(['job_id', 'kind']);
        });
    }
};
