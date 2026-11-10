<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a Workiz job first reached Done or Canceled, as the sweep first saw it:
 * last_status_update moves with every later edit, so a job closed Monday and
 * edited Wednesday left Monday's count (Reports audit, 2026-11-10). Written by
 * WorkizJob::land, wall clock like last_status_update; null on rows closed
 * before it existed, which read last_status_update instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('last_status_update');
        });
    }

    public function down(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
